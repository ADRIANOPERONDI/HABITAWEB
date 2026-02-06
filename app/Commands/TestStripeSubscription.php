<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\PaymentService;

class TestStripeSubscription extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:stripe-subscription';
    protected $description = 'Creates a test subscription with Stripe (Test Mode)';

    public function run(array $params)
    {
        $accountId = array_shift($params);
        $planId = array_shift($params);
        $token = array_shift($params) ?? 'pm_card_visa'; // Default Stripe Test Token

        if (empty($accountId) || empty($planId)) {
            CLI::error('Usage: test:stripe-subscription <account_id> <plan_id> [payment_method_id]');
            return;
        }

        // Force Stripe as active gateway for this test?
        // Actually PaymentService loads primary gateway.
        // We might need to swap primary gateway or instantiate service with forced gateway.
        // PaymentService implementation loads from DB.
        // To test Stripe specifically, we ideally should interpret "active gateway" as Stripe.
        // Or we should update the DB to make Stripe primary first.
        
        // Let's check which gateway is primary. If not Stripe, warn or swap.
        // For CLI test simplicity, let's assume user sets Stripe as primary via admin or we do it here temporarily?
        // Safer: Just warn if Stripe isn't primary.
        
        // Initialize Service
        $service = new PaymentService();

        try {
            // Force Stripe Gateway
            $service->setGateway('stripe');
        } catch (\Exception $e) {
            CLI::error("Error loading Stripe gateway: " . $e->getMessage());
            CLI::write("Make sure Stripe is populated in payment_gateways table.", 'yellow');
            return;
        }

        // Fake Credit Card Data with Token
        $creditCard = [
            'holderName' => 'Stripe Tester',
            'token' => $token // This maps to paymentMethodId in PaymentService
        ];

        CLI::write("Creating Stripe subscription for Account $accountId on Plan $planId...", 'green');
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
                CLI::write("Stripe ID: " . $result['subscription']['subscription_id']);
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
