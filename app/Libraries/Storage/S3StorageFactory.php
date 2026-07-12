<?php

namespace App\Libraries\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

/**
 * Constrói instâncias de S3Storage a partir de Config\Storage (.env storage.s3.*).
 * Usado por Config\Services (quando storage.driver = s3) e pelo comando de
 * migração spark storage:migrate-s3 (que precisa dos discos S3 ANTES de o
 * driver ser trocado no .env).
 */
class S3StorageFactory
{
    /**
     * @param bool $public true = bucket público (imagens de imóvel, logos);
     *                     false = bucket privado (documentos KYC).
     * @throws \RuntimeException se a config storage.s3.* estiver incompleta.
     */
    public static function make(bool $public): S3Storage
    {
        $cfg = config('Storage')->s3;

        $bucket = $public ? ($cfg['bucketPublic'] ?? '') : ($cfg['bucketPrivate'] ?? '');
        if ($bucket === '' || empty($cfg['key']) || empty($cfg['secret'])) {
            throw new \RuntimeException(
                'Storage S3 não configurado: defina storage.s3.key/secret/bucketPublic/bucketPrivate no .env.'
            );
        }

        $clientConfig = [
            'version'     => 'latest',
            'region'      => $cfg['region'] ?: 'us-east-1',
            'credentials' => [
                'key'    => $cfg['key'],
                'secret' => $cfg['secret'],
            ],
            // Timeouts curtos: com FallbackStorage na frente, S3 inacessível
            // precisa falhar RÁPIDO para o upload cair no disco local em vez
            // de segurar a requisição no timeout default do Guzzle.
            'http' => [
                'connect_timeout' => 3,
                'timeout'         => 15,
            ],
        ];

        // Provedores S3-compatíveis não-AWS (R2, Spaces, MinIO) exigem endpoint
        // explícito; path-style evita depender de DNS por-bucket (MinIO local).
        if (! empty($cfg['endpoint'])) {
            $clientConfig['endpoint']                = $cfg['endpoint'];
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $filesystem = new Filesystem(new AwsS3V3Adapter(new S3Client($clientConfig), $bucket));

        return new S3Storage(
            $filesystem,
            publiclyServed: $public,
            publicBaseUrl: $public ? ($cfg['publicBaseUrl'] ?: null) : null,
        );
    }
}
