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

    /**
     * @var string
     */
    public $webhookToken;

    /**
     * @var string
     */
    public $subscriptionBillingType = 'BOLETO';

    /**
     * @var string
     */
    public $singleChargeBillingTypes = 'PIX,BOLETO,CREDIT_CARD';

    public function __construct()
    {
        parent::__construct();
        
        $this->apiKey = env('ASAAS_API_KEY', '');
        $this->environment = env('ASAAS_ENV', 'sandbox');
        $this->webhookToken = env('ASAAS_WEBHOOK_TOKEN', env('ASAAS_WEBHOOK_SECRET', ''));
        $this->webhookSecret = env('ASAAS_WEBHOOK_SECRET', $this->webhookToken);
        $this->subscriptionBillingType = strtoupper(env('ASAAS_SUBSCRIPTION_BILLING_TYPE', $this->subscriptionBillingType));
        $this->singleChargeBillingTypes = strtoupper(env('ASAAS_SINGLE_CHARGE_BILLING_TYPES', $this->singleChargeBillingTypes));
        
        // SECURITY: Warn if critical vars are missing in production
        if (ENVIRONMENT === 'production') {
            if (empty($this->apiKey)) {
                log_message('critical', 'Asaas Config: ASAAS_API_KEY not set in production environment');
            }
            if (empty($this->webhookToken)) {
                log_message('critical', 'Asaas Config: ASAAS_WEBHOOK_TOKEN not set in production environment');
            }
        }
    }

    public function getBaseUrl(): string
    {
        return $this->environment === 'production' 
            ? 'https://api.asaas.com/v3' 
            : 'https://api-sandbox.asaas.com/v3';
    }

    public function getSingleChargeBillingTypes(): array
    {
        $types = array_map('trim', explode(',', $this->singleChargeBillingTypes));
        $types = array_filter($types, static fn ($type) => $type !== '');

        return array_values($types) ?: ['PIX', 'BOLETO', 'CREDIT_CARD'];
    }
}
