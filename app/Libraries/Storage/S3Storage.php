<?php

namespace App\Libraries\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * Backend de object storage S3-compatível (AWS S3, Cloudflare R2, DigitalOcean
 * Spaces, MinIO...) via league/flysystem — Fase 3b do plano de escalabilidade.
 *
 * Recebe um FilesystemOperator já configurado (ver S3StorageFactory), o que
 * mantém esta classe agnóstica de provedor E testável com o adapter em
 * memória (tests/unit/S3StorageTest.php) sem credenciais reais.
 *
 * Mesmos invariantes do LocalStorage:
 * - Caminhos relativos idênticos aos usados hoje (uploads/properties/...),
 *   então a migração preserva as chaves e o banco não muda.
 * - Disco privado NUNCA emite URL pública (getPublicUrl => null, fail-closed);
 *   leitura privada via readStream (proxy autenticado) ou getSignedUrl.
 * - put() consome o arquivo de origem após gravação bem-sucedida.
 */
class S3Storage implements StorageInterface
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly bool $publiclyServed = false,
        private readonly ?string $publicBaseUrl = null,
    ) {
    }

    public function put(string $relativePath, string $sourceFile): string
    {
        $relativePath = $this->sanitize($relativePath);

        $stream = @fopen($sourceFile, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Storage: origem ilegível para {$relativePath}.");
        }

        try {
            $this->filesystem->writeStream($relativePath, $stream);
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException("Storage: falha ao gravar {$relativePath}: " . $e->getMessage(), 0, $e);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($sourceFile);

        return $relativePath;
    }

    public function delete(string $relativePath): bool
    {
        // sanitize() FORA do try: path traversal deve estourar, não virar
        // silenciosamente "false" (o catch é só para falhas do backend).
        $relativePath = $this->sanitize($relativePath);

        try {
            $this->filesystem->delete($relativePath);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function exists(string $relativePath): bool
    {
        $relativePath = $this->sanitize($relativePath);

        try {
            return $this->filesystem->fileExists($relativePath);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getPublicUrl(string $relativePath): ?string
    {
        // Fail-closed: disco privado (documentos KYC) nunca ganha URL pública.
        if (! $this->publiclyServed) {
            return null;
        }

        $path = $this->sanitize($relativePath);

        if (! empty($this->publicBaseUrl)) {
            return rtrim($this->publicBaseUrl, '/') . '/' . $path;
        }

        try {
            // flysystem v3 resolve via a opção 'url' do adapter, se configurada.
            return $this->filesystem->publicUrl($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getSignedUrl(string $relativePath, int $ttlSeconds): ?string
    {
        $relativePath = $this->sanitize($relativePath);

        try {
            return $this->filesystem->temporaryUrl(
                $relativePath,
                (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(1, $ttlSeconds) . 'S'))
            );
        } catch (\Throwable $e) {
            // Adapter sem suporte a URL assinada — chamador usa readStream/proxy.
            return null;
        }
    }

    public function readStream(string $relativePath)
    {
        $relativePath = $this->sanitize($relativePath);

        try {
            return $this->filesystem->readStream($relativePath);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Mesma defesa de path traversal do LocalStorage. */
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
}
