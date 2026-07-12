<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */

    public static function propertyService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('propertyService');
        }

        return new \App\Services\PropertyService();
    }

    public static function leadService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('leadService');
        }

        return new \App\Services\LeadService();
    }

    public static function rankingService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('rankingService');
        }

        return new \App\Services\RankingService();
    }

    public static function promotionService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('promotionService');
        }

        return new \App\Services\PromotionService();
    }

    public static function webhookService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('webhookService');
        }

        return new \App\Services\WebhookService();
    }

    /**
     * Buffer de métricas em Redis (contador de visitas, ranking sujo) —
     * ver App\Libraries\Metrics\RedisMetricsBuffer para o porquê de não usar
     * o cache handler. Singleton por request (conexão lazy, fail-open).
     */
    public static function metricsBuffer($getShared = true): \App\Libraries\Metrics\RedisMetricsBuffer
    {
        if ($getShared) {
            return static::getSharedInstance('metricsBuffer');
        }

        return new \App\Libraries\Metrics\RedisMetricsBuffer();
    }

    /**
     * Disco de uploads públicos (imagens de imóvel, logos, imagens de
     * settings). Backend escolhido por storage.driver no .env:
     * 'local' (default) = FCPATH, servido estático pelo webserver;
     * 's3' = duas vias — tenta o bucket S3 primeiro e cai para o disco
     * local se o S3 falhar (FallbackStorage). Config S3 incompleta também
     * degrada para local (com warning no log) em vez de derrubar o app.
     */
    public static function publicStorage($getShared = true): \App\Libraries\Storage\StorageInterface
    {
        if ($getShared) {
            return static::getSharedInstance('publicStorage');
        }

        return static::buildStorage(true, FCPATH);
    }

    /**
     * Disco de uploads privados (documentos KYC, frames de biometria) — sem
     * URL pública (fail-closed) em QUALQUER backend, inclusive no composto
     * de duas vias. Leitura pelo proxy autenticado (KycFileController) ou
     * signed URL de curta duração.
     */
    public static function privateStorage($getShared = true): \App\Libraries\Storage\StorageInterface
    {
        if ($getShared) {
            return static::getSharedInstance('privateStorage');
        }

        return static::buildStorage(false, WRITEPATH);
    }

    private static function buildStorage(bool $public, string $localBaseDir): \App\Libraries\Storage\StorageInterface
    {
        $local = new \App\Libraries\Storage\LocalStorage($localBaseDir, $public);

        if (config('Storage')->driver !== 's3') {
            return $local;
        }

        try {
            return new \App\Libraries\Storage\FallbackStorage(
                \App\Libraries\Storage\S3StorageFactory::make($public),
                $local
            );
        } catch (\RuntimeException $e) {
            log_message('warning', '[Storage] driver=s3 mas a config está incompleta — operando só com disco local. ' . $e->getMessage());

            return $local;
        }
    }
}
