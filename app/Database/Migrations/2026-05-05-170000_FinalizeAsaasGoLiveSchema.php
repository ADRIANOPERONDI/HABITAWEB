<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class FinalizeAsaasGoLiveSchema extends Migration
{
    public function up()
    {
        $this->ensureAsaasPrimaryGateway();
        $this->ensureAsaasConfigs();
        $this->ensureWebhookLogIdempotency();
    }

    public function down()
    {
        if ($this->db->tableExists('webhook_logs')) {
            $this->db->query('DROP INDEX IF EXISTS webhook_logs_event_unique');
        }
    }

    private function ensureAsaasPrimaryGateway(): void
    {
        if (!$this->db->tableExists('payment_gateways')) {
            return;
        }

        $gateway = $this->db->table('payment_gateways')
            ->where('code', 'asaas')
            ->get()
            ->getRowArray();

        if (!$gateway) {
            return;
        }

        $this->db->table('payment_gateways')->set(['is_primary' => false])->update();
        $this->db->table('payment_gateways')
            ->where('id', $gateway['id'])
            ->update([
                'is_active' => true,
                'is_primary' => true,
                'supported_methods' => json_encode(['PIX', 'BOLETO', 'CREDIT_CARD']),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function ensureAsaasConfigs(): void
    {
        if (
            !$this->db->tableExists('payment_gateways')
            || !$this->db->tableExists('payment_gateway_configs')
        ) {
            return;
        }

        $gateway = $this->db->table('payment_gateways')
            ->where('code', 'asaas')
            ->get()
            ->getRowArray();

        if (!$gateway) {
            return;
        }

        $token = env('ASAAS_WEBHOOK_TOKEN', env('ASAAS_WEBHOOK_SECRET', ''));
        $secret = env('ASAAS_WEBHOOK_SECRET', $token);

        $configs = [
            'api_key' => [env('ASAAS_API_KEY', ''), 'string', true, 1],
            'environment' => [env('ASAAS_ENV', 'sandbox'), 'select', false, 2],
            'webhook_secret' => [$secret, 'string', true, 3],
            'webhook_token' => [$token, 'string', true, 4],
        ];

        foreach ($configs as $key => [$value, $type, $sensitive, $order]) {
            $this->upsertGatewayConfig((int) $gateway['id'], $key, (string) $value, $type, $sensitive, $order);
        }
    }

    private function ensureWebhookLogIdempotency(): void
    {
        if (!$this->db->tableExists('webhook_logs')) {
            return;
        }

        $driver = strtolower((string) ($this->db->DBDriver ?? ''));

        if (str_contains($driver, 'postgre')) {
            $this->db->query("
                DELETE FROM webhook_logs
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id,
                               ROW_NUMBER() OVER (
                                   PARTITION BY event_type, event_id
                                   ORDER BY id
                               ) AS duplicate_rank
                        FROM webhook_logs
                        WHERE event_id IS NOT NULL
                    ) ranked
                    WHERE ranked.duplicate_rank > 1
                )
            ");

            $this->db->query("
                CREATE UNIQUE INDEX IF NOT EXISTS webhook_logs_event_unique
                ON webhook_logs (event_type, event_id)
                WHERE event_id IS NOT NULL
            ");

            return;
        }

        try {
            $this->forge->addKey(['event_type', 'event_id'], false, true, 'webhook_logs_event_unique');
            $this->forge->processIndexes('webhook_logs');
        } catch (\Throwable $e) {
            log_message('warning', 'Could not create webhook_logs unique index: ' . $e->getMessage());
        }
    }

    private function upsertGatewayConfig(
        int $gatewayId,
        string $key,
        string $value,
        string $type,
        bool $sensitive,
        int $order
    ): void {
        $table = $this->db->table('payment_gateway_configs');
        $existing = $table
            ->where('gateway_id', $gatewayId)
            ->where('config_key', $key)
            ->get()
            ->getRowArray();

        $data = [
            'gateway_id' => $gatewayId,
            'config_key' => $key,
            'config_value' => $sensitive ? $this->encryptConfigValue($value) : $value,
            'config_type' => $type,
            'is_sensitive' => $sensitive,
            'display_order' => $order,
        ];

        if ($existing) {
            $this->db->table('payment_gateway_configs')
                ->where('id', $existing['id'])
                ->update($data);
            return;
        }

        $this->db->table('payment_gateway_configs')->insert($data);
    }

    private function encryptConfigValue(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return base64_encode(\Config\Services::encrypter()->encrypt($value));
    }
}
