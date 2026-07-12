<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuração dos discos de armazenamento de uploads (ver
 * App\Libraries\Storage\StorageInterface e os services publicStorage /
 * privateStorage em Config\Services).
 *
 * Chaves .env correspondentes (auto-bind do CI4, mesmo padrão de cache.*):
 *   storage.driver           = local | s3
 *   storage.s3.key           = ...
 *   storage.s3.secret        = ...
 *   storage.s3.region        = ...
 *   storage.s3.bucketPublic  = ...
 *   storage.s3.bucketPrivate = ...
 *   storage.s3.endpoint      = ...  (provedores S3-compatíveis não-AWS: R2, Spaces, MinIO)
 *   storage.s3.publicBaseUrl = ...  (CDN na frente do bucket público, se houver)
 *
 * 'local' (padrão) preserva o comportamento histórico: uploads públicos em
 * FCPATH (public/uploads/...) e privados em WRITEPATH (writable/uploads/kyc).
 * O driver 's3' está reservado para a Fase 3b — exige implementar S3Storage
 * (league/flysystem) e uma decisão de infra sobre provedor/credenciais.
 */
class Storage extends BaseConfig
{
    public string $driver = 'local';

    /**
     * @var array{key: string, secret: string, region: string, bucketPublic: string, bucketPrivate: string, endpoint: string, publicBaseUrl: string}
     */
    public array $s3 = [
        'key'           => '',
        'secret'        => '',
        'region'        => '',
        'bucketPublic'  => '',
        'bucketPrivate' => '',
        'endpoint'      => '',
        'publicBaseUrl' => '',
    ];
}
