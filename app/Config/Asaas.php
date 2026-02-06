<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Asaas extends BaseConfig
{
    /**
     * @var string
     */
    public $apiKey;

    /**
     * @var string 'sandbox' or 'production'
     */
    public $environment = 'sandbox';

    /**
     * @var string
     */
    public $webhookSecret;

    public function __construct()
    {
        parent::__construct();
        
        $this->apiKey = env('ASAAS_API_KEY', '');
        $this->environment = env('ASAAS_ENV', 'sandbox');
        $this->webhookSecret = env('ASAAS_WEBHOOK_SECRET', '');
    }

    public function getBaseUrl(): string
    {
        return $this->environment === 'production' 
            ? 'https://api.asaas.com/v3' 
            : 'https://sandbox.asaas.com/api/v3';
    }
}
