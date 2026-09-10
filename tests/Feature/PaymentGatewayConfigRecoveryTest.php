<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayConfigModel;
use Tests\Support\HabitawebTestCase;

/**
 * `PaymentGatewayConfigModel::recoverSensitiveValue()` — a config
 * `environment` do Asaas guarda um literal ('sandbox'/'production'), nunca
 * devia estar `is_sensitive=true` (migration 2026-07-14-120000 já corrigiu
 * isso uma vez em produção). Se a linha voltar a ficar sensível por
 * qualquer motivo e a descriptografia falhar (chave rotacionada, dado
 * corrompido), o sistema não pode ficar preso num loop de erro nem cair
 * silenciosamente pra sandbox pra sempre — precisa se autocurar a partir
 * de `ASAAS_ENV` e voltar a gravar como não-sensível.
 */
final class PaymentGatewayConfigRecoveryTest extends HabitawebTestCase
{
    /**
     * `code` único por chamada — `habitaweb_test` local carrega uma linha
     * `asaas` permanente de seeds manuais antigos (fora da transação de
     * isolamento por teste, mesmo motivo do PlanSeeder), e um `code`
     * hardcoded colidiria com ela.
     */
    private function makeGateway(): int
    {
        $db = \Config\Database::connect();
        $db->table('payment_gateways')->insert([
            'code'       => 'fake_asaas_' . bin2hex(random_bytes(4)),
            'name'       => 'Asaas',
            'class_name' => 'App\\PaymentGateways\\AsaasGateway',
            'is_active'  => true,
            'is_primary' => true,
        ]);

        return (int) $db->insertID();
    }

    public function testConfigEnvironmentSeAutocuraQuandoDescriptografiaFalha(): void
    {
        $gatewayId = $this->makeGateway();
        $db = \Config\Database::connect();

        // Simula o estado quebrado observado em produção: 'environment'
        // marcado is_sensitive=true, com um valor que não é ciphertext
        // válido pra chave atual (decrypt lança EncryptionException).
        $db->table('payment_gateway_configs')->insert([
            'gateway_id'   => $gatewayId,
            'config_key'   => 'environment',
            'config_value' => base64_encode('lixo-nao-e-ciphertext-valido'),
            'config_type'  => 'select',
            'is_sensitive' => true,
        ]);

        $model = new PaymentGatewayConfigModel();
        $config = $model->getGatewayConfig($gatewayId);

        // Recuperado de ASAAS_ENV (=sandbox no ambiente de teste), não vazio.
        $this->assertSame('sandbox', $config['environment']);

        // Autocurado: a linha não fica presa sensível+quebrada pra sempre.
        $row = $db->table('payment_gateway_configs')
            ->where('gateway_id', $gatewayId)
            ->where('config_key', 'environment')
            ->get()
            ->getRow();

        // Postgres devolve booleano como 't'/'f' numa leitura crua (sem
        // Model/$casts) — 'f' é truthy em PHP, então (bool) aqui mentiria.
        $this->assertSame('f', (string) $row->is_sensitive);
        $this->assertSame('sandbox', $row->config_value);
    }

    public function testConfigApiKeyContinuaCifradaAposRecuperacao(): void
    {
        $gatewayId = $this->makeGateway();
        $db = \Config\Database::connect();

        $db->table('payment_gateway_configs')->insert([
            'gateway_id'   => $gatewayId,
            'config_key'   => 'api_key',
            'config_value' => base64_encode('lixo-nao-e-ciphertext-valido'),
            'config_type'  => 'string',
            'is_sensitive' => true,
        ]);

        $model = new PaymentGatewayConfigModel();
        $config = $model->getGatewayConfig($gatewayId);

        // .env de teste não define ASAAS_API_KEY -> sem fallback, string vazia.
        $this->assertSame('', $config['api_key']);

        // Diferente de 'environment': api_key É segredo de verdade — se algum
        // dia recuperar de ASAAS_API_KEY, tem que continuar is_sensitive=true
        // (cifrado), nunca virar texto puro no banco.
        $row = $db->table('payment_gateway_configs')
            ->where('gateway_id', $gatewayId)
            ->where('config_key', 'api_key')
            ->get()
            ->getRow();

        // Ver comentário no teste anterior sobre 't'/'f' cru do Postgres.
        $this->assertSame('t', (string) $row->is_sensitive);
    }
}
