<?php

namespace App\Libraries\Storage;

/**
 * Backend de disco local — comportamento idêntico ao que os call sites faziam
 * diretamente com mkdir()/move()/unlink() antes da consolidação (Fase 3a do
 * plano de escalabilidade). Dois "discos" são instanciados em
 * Config\Services: público (base FCPATH, servido estático pelo webserver) e
 * privado (base WRITEPATH, fora do webroot — documentos KYC).
 *
 * Nota de escala: disco local só funciona com UMA instância de app (ou com o
 * diretório de uploads num filesystem compartilhado, ex. NFS, montado no mesmo
 * caminho em todas as instâncias). Para object storage, implementar
 * S3Storage nesta mesma interface e trocar em Config\Services — nenhum call
 * site precisa mudar.
 */
class LocalStorage implements StorageInterface
{
    public function __construct(
        private readonly string $baseDir,
        private readonly bool $publiclyServed = false,
    ) {
    }

    public function put(string $relativePath, string $sourceFile): string
    {
        $relativePath = $this->sanitize($relativePath);
        $destination  = $this->baseDir . $relativePath;
        $directory    = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Storage: não foi possível criar o diretório {$directory}.");
        }

        // copy() em vez de rename()/move_uploaded_file(): rename() falha entre
        // filesystems (o tmp de upload costuma estar em outra partição) e
        // move_uploaded_file() só aceita arquivos vindos de um POST real da
        // SAPI HTTP — nunca em CLI/testes. copy() cobre todos os casos.
        if (! @copy($sourceFile, $destination)) {
            throw new \RuntimeException("Storage: falha ao gravar {$relativePath}.");
        }

        @unlink($sourceFile);

        return $relativePath;
    }

    public function delete(string $relativePath): bool
    {
        $path = $this->resolveExisting($relativePath);

        return $path !== null && @unlink($path);
    }

    public function exists(string $relativePath): bool
    {
        return $this->resolveExisting($relativePath) !== null;
    }

    public function getPublicUrl(string $relativePath): ?string
    {
        // Disco privado: fail-closed por contrato (documentos KYC nunca podem
        // ganhar URL pública), não só por convenção do chamador.
        return $this->publiclyServed ? base_url($this->sanitize($relativePath)) : null;
    }

    public function getSignedUrl(string $relativePath, int $ttlSeconds): ?string
    {
        // Disco local não emite URL assinada — leitura privada é servida pelo
        // proxy autenticado (KycFileController) via readStream().
        return null;
    }

    public function readStream(string $relativePath)
    {
        $path = $this->resolveExisting($relativePath);

        if ($path === null) {
            return null;
        }

        $stream = @fopen($path, 'rb');

        return $stream === false ? null : $stream;
    }

    /**
     * Rejeita null byte e qualquer segmento ".." — substitui (e mantém) a
     * defesa de path traversal que os call sites faziam com realpath().
     */
    private function sanitize(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === ''
            || str_contains($relativePath, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $relativePath)) {
            throw new \InvalidArgumentException('Storage: caminho relativo inválido.');
        }

        return $relativePath;
    }

    /**
     * Resolve o caminho apenas se o arquivo existe E está contido no baseDir
     * deste disco (defesa extra contra symlink/traversal em leituras/exclusões).
     */
    private function resolveExisting(string $relativePath): ?string
    {
        $real = realpath($this->baseDir . $this->sanitize($relativePath));
        $root = realpath($this->baseDir);

        if ($real === false || $root === false || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }
}
