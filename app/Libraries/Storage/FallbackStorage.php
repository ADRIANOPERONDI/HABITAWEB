<?php

namespace App\Libraries\Storage;

/**
 * Disco composto em duas vias: tenta o backend primário (S3) primeiro e cai
 * para o secundário (disco local) quando o primário falha — o sistema nunca
 * depende de um único backend para aceitar uploads.
 *
 * Regras:
 *  - put(): grava no primário; se a gravação falhar (S3 fora do ar, bucket
 *    inacessível...), loga um warning e grava no local. O upload só falha se
 *    AMBOS falharem.
 *  - Leituras (exists/getPublicUrl/getSignedUrl/readStream): resolvem onde o
 *    arquivo realmente está — um acervo pode ficar espalhado entre S3 e local
 *    (ex.: uploads feitos durante uma indisponibilidade do S3) e tudo continua
 *    sendo servido.
 *  - delete(): remove das duas vias (delete de inexistente é no-op).
 *  - Fail-closed do disco privado é preservado por composição: os dois lados
 *    já retornam null em getPublicUrl() quando privados.
 *
 * A localização (primário/local/nenhum) é cacheada (Redis em produção) para
 * não pagar um HEAD no S3 por imagem a cada render de página; put() e delete()
 * mantêm o cache coerente, e como o cache é compartilhado entre instâncias a
 * invalidação vale para todas.
 */
class FallbackStorage implements StorageInterface
{
    /** TTL do cache de localização para arquivo encontrado. */
    private const LOC_TTL = 3600;

    /**
     * TTL curto para "não existe em lugar nenhum" — evita re-sondar o S3 a
     * cada render por variantes que nunca foram geradas (imagens legadas),
     * sem segurar por muito tempo um negativo que ficou obsoleto. put()
     * sobrescreve a entrada imediatamente ao gravar.
     */
    private const MISS_TTL = 300;

    public function __construct(
        private readonly StorageInterface $primary,
        private readonly StorageInterface $fallback,
    ) {
    }

    public function put(string $relativePath, string $sourceFile): string
    {
        try {
            $stored = $this->primary->put($relativePath, $sourceFile);
            $this->rememberLocation($stored, 'p');

            return $stored;
        } catch (\RuntimeException $e) {
            // Path traversal (InvalidArgumentException) NÃO cai aqui de
            // propósito: deve estourar, não ser "resolvido" pelo fallback.
            log_message('warning', '[Storage] Primário falhou ao gravar ' . $relativePath . ' — usando disco local. Erro: ' . $e->getMessage());
        }

        $stored = $this->fallback->put($relativePath, $sourceFile);
        $this->rememberLocation($stored, 'f');

        return $stored;
    }

    public function delete(string $relativePath): bool
    {
        $primary  = $this->primary->delete($relativePath);
        $fallback = $this->fallback->delete($relativePath);

        cache()->delete($this->locationKey($relativePath));

        return $primary || $fallback;
    }

    public function exists(string $relativePath): bool
    {
        return $this->locate($relativePath) !== null;
    }

    public function getPublicUrl(string $relativePath): ?string
    {
        $disk = $this->locate($relativePath);

        // Arquivo em lugar nenhum: delega ao local, que preserva o
        // comportamento histórico (base_url no disco público, null no
        // privado — fail-closed continua valendo).
        return ($disk ?? $this->fallback)->getPublicUrl($relativePath);
    }

    public function getSignedUrl(string $relativePath, int $ttlSeconds): ?string
    {
        return $this->locate($relativePath)?->getSignedUrl($relativePath, $ttlSeconds);
    }

    public function readStream(string $relativePath)
    {
        // Sem cache aqui: caminho raro (proxy de KYC) e um stream nulo do
        // primário já cai naturalmente para o local.
        return $this->primary->readStream($relativePath)
            ?? $this->fallback->readStream($relativePath);
    }

    /**
     * Descobre em qual via o arquivo está ('p' primário, 'f' local), com
     * cache para poupar chamadas de rede no caminho quente das views.
     */
    private function locate(string $relativePath): ?StorageInterface
    {
        $key = $this->locationKey($relativePath);

        $cached = cache($key);
        if ($cached === 'p') {
            return $this->primary;
        }
        if ($cached === 'f') {
            return $this->fallback;
        }
        if ($cached === 'n') {
            return null;
        }

        if ($this->primary->exists($relativePath)) {
            $this->rememberLocation($relativePath, 'p');

            return $this->primary;
        }

        if ($this->fallback->exists($relativePath)) {
            $this->rememberLocation($relativePath, 'f');

            return $this->fallback;
        }

        cache()->save($key, 'n', self::MISS_TTL);

        return null;
    }

    private function rememberLocation(string $relativePath, string $location): void
    {
        cache()->save($this->locationKey($relativePath), $location, self::LOC_TTL);
    }

    private function locationKey(string $relativePath): string
    {
        return 'stloc_' . md5(ltrim($relativePath, '/'));
    }
}
