<?php

namespace App\Services;

use Config\Asaas;
use Exception;

class AsaasService
{
    protected $config;
    protected $client;

    public function __construct()
    {
        $this->config = new Asaas();
        $this->loadDbConfig();
        $this->client = \Config\Services::curlrequest();
    }

    /**
     * Load config from Database if available
     */
    protected function loadDbConfig()
    {
        try {
            // Only try if models exist
            if (class_exists('App\Models\PaymentGatewayModel')) {
                $gwModel = model('App\Models\PaymentGatewayModel');
                $cfgModel = model('App\Models\PaymentGatewayConfigModel');
                
                $gateway = $gwModel->where('code', 'asaas')->first();
                if ($gateway && $gateway->is_active) {
                    $configs = $cfgModel->getGatewayConfig($gateway->id, true);
                    
                    if (!empty($configs['api_key'])) {
                        $this->config->apiKey = $configs['api_key'];
                    }
                    if (!empty($configs['environment'])) {
                        $this->config->environment = $configs['environment'];
                    }
                    if (!empty($configs['webhook_secret'])) {
                        $this->config->webhookSecret = $configs['webhook_secret'];
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Error loading Asaas DB Config: ' . $e->getMessage());
        }
    }

    /**
     * Generic request method for Asaas API
     */
    protected function request(string $method, string $endpoint, array $data = [])
    {
        $url = $this->config->getBaseUrl() . $endpoint;
        
        $options = [
            'headers' => [
                'access_token' => $this->config->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent'   => 'PortalImoveis/1.0'
            ],
            'http_errors' => false, // Handle errors manually
            'verify'      => false  // Disable SSL verify for sandbox/dev if needed, or generally true for prod (CI4 handles this)
        ];

        if (!empty($data)) {
            $options['json'] = $data;
        }

        try {
            $response = $this->client->request($method, $url, $options);
            $body = json_decode($response->getBody(), true);
            $code = $response->getStatusCode();

            if ($code >= 400) {
                $errorMsg = isset($body['errors'][0]['description']) ? $body['errors'][0]['description'] : 'Unknown Asaas Error';
                log_message('error', "Asaas API Error [$code]: $errorMsg | URL: $url");
                throw new Exception("Asaas Error: $errorMsg", $code);
            }

            return $body;

        } catch (\Exception $e) {
            log_message('critical', 'Asaas Connection Error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // ... rest of the file ...
    public function createCustomer(array $customerData)
    {
        // Check if customer exists by CPF/CNPJ if external_id not provided
        if (!empty($customerData['cpfCnpj'])) {
            try {
                $existing = $this->request('GET', '/customers?cpfCnpj=' . $customerData['cpfCnpj']);
                if (!empty($existing['data'])) {
                    return $existing['data'][0];
                }
            } catch (\Exception $e) {
                // Ignore search error, try create
            }
        }

        return $this->request('POST', '/customers', $customerData);
    }
    
    // ... rest of methods ...
    public function createPayment(array $paymentData)
    {
        return $this->request('POST', '/payments', $paymentData);
    }

    /**
     * Create a new Payment with Tokenization request
     */
    public function createPaymentWithTokenization(array $paymentData)
    {
        $paymentData['tokenizeCreditCard'] = true;
        return $this->request('POST', '/payments', $paymentData);
    }

    /**
     * Create a Subscription
     */
    public function createSubscription(array $subscriptionData)
    {
        return $this->request('POST', '/subscriptions', $subscriptionData);
    }

    /**
     * Get Subscription details
     */
    public function getSubscription(string $id)
    {
        return $this->request('GET', '/subscriptions/' . $id);
    }
    
    /**
     * Get Payment details
     */
    public function getPayment(string $id)
    {
        return $this->request('GET', '/payments/' . $id);
    }
    
    /**
     * Get Payments for a Subscription
     */
    public function getSubscriptionPayments(string $subscriptionId)
    {
        return $this->request('GET', '/subscriptions/' . $subscriptionId . '/payments');
    }

    /**
     * Get Pix QR Code for a payment
     */
    public function getPixQrCode(string $paymentId)
    {
        return $this->request('GET', '/payments/' . $paymentId . '/pixQrCode');
    }
    
    /**
     * Get Boleto identification line
     */
    public function getBoletoDetails(string $paymentId)
    {
        return $this->request('GET', '/payments/' . $paymentId . '/identificationField');
    }
    /**
     * Get current environment
     */
    public function getEnvironment()
    {
        return $this->config->environment;
    }

    public function getWebhookSecret()
    {
        return $this->config->webhookSecret;
    }
}
