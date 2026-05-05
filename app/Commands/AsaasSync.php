<?php

namespace App\Commands;

use App\Services\PaymentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AsaasSync extends BaseCommand
{
    protected $group = 'Asaas';
    protected $name = 'asaas:sync';
    protected $description = 'Reconciles pending Asaas payments and subscriptions with local records.';

    public function run(array $params)
    {
        $limit = max(1, (int) ($params[0] ?? 200));
        $db = \Config\Database::connect();
        $paymentService = new PaymentService();

        try {
            $paymentService->setGateway('asaas');
        } catch (\Throwable $e) {
            CLI::error('Could not load Asaas gateway: ' . $e->getMessage());
            return;
        }

        $accountIds = $this->collectAccountIds($db, $limit);
        $subscriptions = $this->collectSubscriptions($db, $limit);

        CLI::write('Asaas reconciliation started.', 'cyan');
        CLI::write('Accounts to inspect: ' . count($accountIds), 'white');
        CLI::write('Subscriptions to inspect: ' . count($subscriptions), 'white');

        $syncedAccounts = 0;
        foreach ($accountIds as $accountId) {
            try {
                $paymentService->syncPendingPayments((int) $accountId);
                $syncedAccounts++;
            } catch (\Throwable $e) {
                CLI::write("Account {$accountId}: " . $e->getMessage(), 'red');
            }
        }

        $syncedSubscriptions = 0;
        foreach ($subscriptions as $subscription) {
            try {
                if ($paymentService->syncSubscriptionStatus((int) $subscription->id)) {
                    $syncedSubscriptions++;
                }
            } catch (\Throwable $e) {
                CLI::write("Subscription {$subscription->id}: " . $e->getMessage(), 'red');
            }
        }

        CLI::write('Asaas reconciliation finished.', 'green');
        CLI::write("Accounts processed: {$syncedAccounts}", 'white');
        CLI::write("Subscriptions updated/checked: {$syncedSubscriptions}", 'white');
    }

    private function collectAccountIds($db, int $limit): array
    {
        $ids = [];

        if ($db->tableExists('subscriptions')) {
            $rows = $db->table('subscriptions')
                ->select('account_id')
                ->distinct()
                ->where('account_id IS NOT NULL')
                ->where('asaas_customer_id IS NOT NULL')
                ->whereIn('status', ['ACTIVE', 'PENDING', 'AWAITING_PAYMENT', 'OVERDUE', 'SUSPENDED'])
                ->limit($limit)
                ->get()
                ->getResult();

            foreach ($rows as $row) {
                $ids[(int) $row->account_id] = (int) $row->account_id;
            }
        }

        if ($db->tableExists('payment_transactions')) {
            $rows = $db->table('payment_transactions')
                ->select('account_id')
                ->distinct()
                ->where('account_id IS NOT NULL')
                ->where('gateway', 'asaas')
                ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT', 'OVERDUE'])
                ->limit($limit)
                ->get()
                ->getResult();

            foreach ($rows as $row) {
                $ids[(int) $row->account_id] = (int) $row->account_id;
            }
        }

        return array_values($ids);
    }

    private function collectSubscriptions($db, int $limit): array
    {
        if (!$db->tableExists('subscriptions')) {
            return [];
        }

        return $db->table('subscriptions')
            ->select('id')
            ->where('asaas_subscription_id IS NOT NULL')
            ->whereIn('status', ['ACTIVE', 'PENDING', 'AWAITING_PAYMENT', 'OVERDUE', 'SUSPENDED'])
            ->orderBy('updated_at', 'ASC')
            ->limit($limit)
            ->get()
            ->getResult();
    }
}
