<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnsureAsaasPaymentSchema extends Migration
{
    public function up()
    {
        $this->ensurePaymentTransactionColumns();
        $this->ensureAsaasGateway();
    }

    public function down()
    {
        // Non-destructive repair migration. Keep payment history and gateway config intact.
    }

    private function ensurePaymentTransactionColumns(): void
    {
        if (!$this->db->tableExists('payment_transactions')) {
            return;
        }

        $fields = [
            'subscription_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'id',
            ],
            'gateway' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'account_id',
            ],
            'gateway_transaction_id' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'gateway',
            ],
            'gateway_code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'gateway_transaction_id',
            ],
            'gateway_customer_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'gateway_code',
            ],
            'gateway_subscription_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'gateway_customer_id',
            ],
            'payment_method' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'gateway_subscription_id',
            ],
            'currency' => [
                'type' => 'VARCHAR',
                'constraint' => 3,
                'default' => 'BRL',
                'after' => 'amount',
            ],
            'metadata' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'payment_method',
            ],
        ];

        foreach ($fields as $field => $definition) {
            if (!$this->columnExists('payment_transactions', $field)) {
                try {
                    $this->forge->addColumn('payment_transactions', [$field => $definition]);
                } catch (\Throwable $e) {
                    if (!$this->columnExists('payment_transactions', $field)) {
                        throw $e;
                    }
                }
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $driver = strtolower((string) ($this->db->DBDriver ?? ''));

        if (str_contains($driver, 'postgre')) {
            return (bool) $this->db->query(
                'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1',
                [$table, $column]
            )->getRowArray();
        }

        try {
            return $this->db->fieldExists($column, $table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ensureAsaasGateway(): void
    {
        if (!$this->db->tableExists('payment_gateways')) {
            return;
        }

        $gatewayTable = $this->db->table('payment_gateways');
        $gateway = $gatewayTable->where('code', 'asaas')->get()->getRowArray();

        $data = [
            'code' => 'asaas',
            'name' => 'Asaas',
            'class_name' => 'App\\PaymentGateways\\AsaasGateway',
            'is_active' => true,
            'is_primary' => true,
            'logo_url' => null,
            'description' => 'Gateway brasileiro com suporte a PIX, Boleto e Cartao de Credito',
            'supported_methods' => json_encode(['PIX', 'BOLETO', 'CREDIT_CARD']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->db->fieldExists('is_primary', 'payment_gateways')) {
            $gatewayTable->set(['is_primary' => false])->update();
        }

        if ($gateway) {
            $gatewayTable->where('id', $gateway['id'])->update($data);
            $gatewayId = (int) $gateway['id'];
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $gatewayTable->insert($data);
            $gatewayId = (int) $this->db->insertID();
        }

        $this->ensureAsaasGatewayConfigs($gatewayId);
    }

    private function ensureAsaasGatewayConfigs(int $gatewayId): void
    {
        if (!$gatewayId || !$this->db->tableExists('payment_gateway_configs')) {
            return;
        }

        $configs = [
            'api_key' => [
                'value' => env('ASAAS_API_KEY', ''),
                'type' => 'string',
                'sensitive' => true,
                'order' => 1,
            ],
            'environment' => [
                'value' => env('ASAAS_ENV', 'sandbox'),
                'type' => 'select',
                'sensitive' => false,
                'order' => 2,
            ],
            'webhook_secret' => [
                'value' => env('ASAAS_WEBHOOK_SECRET', env('ASAAS_WEBHOOK_TOKEN', '')),
                'type' => 'string',
                'sensitive' => true,
                'order' => 3,
            ],
            'webhook_token' => [
                'value' => env('ASAAS_WEBHOOK_TOKEN', env('ASAAS_WEBHOOK_SECRET', '')),
                'type' => 'string',
                'sensitive' => true,
                'order' => 4,
            ],
        ];

        foreach ($configs as $key => $config) {
            $this->upsertGatewayConfig(
                $gatewayId,
                $key,
                (string) $config['value'],
                (string) $config['type'],
                (bool) $config['sensitive'],
                (int) $config['order']
            );
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
            $table->where('id', $existing['id'])->update($data);
            return;
        }

        $table->insert($data);
    }

    private function encryptConfigValue(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $encrypter = \Config\Services::encrypter();

        return base64_encode($encrypter->encrypt($value));
    }
}
