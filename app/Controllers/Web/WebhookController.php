<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\SubscriptionModel;
use App\Models\PaymentTransactionModel;
use CodeIgniter\Config\Factories;

class WebhookController extends BaseController
{
    public function asaas()
    {
        // 1. Validate Secret (Optional HMAC or Header)
        $secret = env('ASAAS_WEBHOOK_SECRET');
        $headerSecret = $this->request->getHeaderLine('asaas-access-token'); // Asaas sends this if configured 
        
        if (!empty($secret) && $secret !== $headerSecret) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Invalid Secret']);
        }

        // 2. Get Payload
        $json = $this->request->getJSON(true);
        if (!$json || !isset($json['event'])) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid Payload']);
        }

        $event = $json['event'];
        $payment = $json['payment'];
         // Asaas payload structure:
         // { "event": "PAYMENT_CONFIRMED", "payment": { "id": "pay_...", "externalReference": "...", ... } }

        log_message('info', "Asaas Webhook Received: $event | ID: {$payment['id']}");

        $subscriptionModel = Factories::models(SubscriptionModel::class);
        $db = \Config\Database::connect();

        try {
            // Transaction logic
            
            // Find subscription by Asaas Subscription ID (if recurring) or Payment External Reference?
            // Our PaymentService created subscription with 'externalReference' = 'plan_X_acc_Y'
            // But Asaas sends 'subscription' field in payment object if linked.
            
            $asaasSubscriptionId = $payment['subscription'] ?? null;
            
            if ($asaasSubscriptionId) {
                // It is a subscription payment
                $localSub = $subscriptionModel->where('asaas_subscription_id', $asaasSubscriptionId)->first();
                
                if ($localSub) {
                    // Update Subscription Status based on event
                    if (in_array($event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'])) {
                        $subscriptionModel->update($localSub->id, [
                            'status' => 'ATIVA', // or ACTIVE
                            'updated_at' => date('Y-m-d H:i:s')
                            // Maybe extend expiry date? Usually Asaas handles next due date.
                        ]);
                        
                        // Update Transaction Log
                         $transactionModel = model(PaymentTransactionModel::class);
                         $transactionModel->upsertTransaction([
                            'account_id' => $localSub->account_id,
                            'external_id' => $payment['id'],
                            'method' => $payment['billingType'],
                            'amount' => $payment['value'],
                            'status' => 'CONFIRMED',
                            'type' => 'SUBSCRIPTION',
                            'paid_at' => date('Y-m-d H:i:s')
                        ]);
                    } 
                    elseif ($event === 'PAYMENT_OVERDUE') {
                        $subscriptionModel->update($localSub->id, ['status' => 'INADIMPLENTE']);
                    }
                }
            } else {
                // One-time payment (Turbo) - Logic to be implemented later
                // Check payment_transactions table by external_id
            }

            return $this->response->setJSON(['status' => 'ok']);

        } catch (\Exception $e) {
            log_message('error', 'Webhook Error: ' . $e->getMessage());
            return $this->response->setStatusCode(500);
        }
    }
}
