<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\PaymentService;

class TestAsaasSubscription extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:asaas-subscription';
    protected $description = 'Creates a test subscription with credit card in Asaas';

    public function run(array $params)
    {
        $accountId = array_shift($params);
        $planId = array_shift($params);

        if (empty($accountId) || empty($planId)) {
            CLI::error('Usage: test:asaas-subscription <account_id> <plan_id>');
            return;
        }

        $service = new PaymentService();

        // Fake Credit Card Data for Sandbox
        // Asaas Sandbox allows specific test cards
        $creditCard = [
            'holderName' => 'JOSE SILVA',
            'number' => '4444444444444444', // Mastercard valid for testing
            'expiryMonth' => '12',
            'expiryYear' => '2028',
            'ccv' => '123'
        ];

        CLI::write("Creating subscription for Account $accountId on Plan $planId...", 'yellow');

        try {
            $result = $service->initializeSubscription(
                (int)$accountId, 
                (int)$planId, 
                'CREDIT_CARD', 
                $creditCard
            );

            if ($result['success']) {
                CLI::write('✅ Subscription Created Successfully!', 'green');
                CLI::write("Local ID: " . $result['local_id']);
                CLI::write("Asaas ID: " . $result['subscription']['subscription_id']);
                CLI::write("Status: " . $result['subscription']['status']);
            } else {
                CLI::write('❌ Failed to create subscription.', 'red');
                print_r($result);
            }

        } catch (\Exception $e) {
            CLI::error('Exception: ' . $e->getMessage());
            CLI::write($e->getTraceAsString());
        }
    }
}
