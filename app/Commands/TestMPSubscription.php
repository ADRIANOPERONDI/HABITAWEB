<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\PaymentService;

class TestMPSubscription extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:mp-subscription';
    protected $description = 'Creates a test subscription with Mercado Pago (Test Mode)';

    public function run(array $params)
    {
        $accountId = array_shift($params);
        $planId = array_shift($params);
        $token = array_shift($params) ?? 'test_card_token';

        if (empty($accountId) || empty($planId)) {
            CLI::error('Usage: test:mp-subscription <account_id> <plan_id> [card_token]');
            return;
        }

        $service = new PaymentService();

        try {
            // Force Mercado Pago Gateway
            $service->setGateway('mercadopago');
        } catch (\Exception $e) {
            CLI::error("Error loading MP gateway: " . $e->getMessage());
            CLI::write("Make sure Mercado Pago is populated in payment_gateways table.", 'yellow');
            return;
        }

        // Fake Credit Card Data with Token
        $creditCard = [
            'holderName' => 'MP Tester',
            'token' => $token
        ];

        CLI::write("Creating MP subscription for Account $accountId on Plan $planId...", 'green');
        CLI::write("Using Token: $token", 'cyan');

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
                CLI::write("MP ID: " . $result['subscription']['subscription_id']);
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
