<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Normaliza a linha 'environment' de payment_gateway_configs.
 *
 * Em produção essa linha estava marcada is_sensitive = true e com o valor
 * criptografado por uma encryption.key antiga: a descriptografia falhava
 * ("Decrypting: authentication failed."), getGatewayConfig devolvia '' e o
 * AsaasGateway caía no default 'sandbox' — com a chave de API de produção,
 * o Asaas respondia 401 invalid_environment a cada asaas:sync.
 *
 * 'environment' não é segredo (é 'sandbox' ou 'production'); todas as
 * migrations/seeders o criam com is_sensitive = false. Aqui forçamos o flag
 * de volta e, se o valor armazenado não for um dos dois literais válidos
 * (ou seja, é ciphertext irrecuperável), regravamos a partir de ASAAS_ENV.
 */
class FixEnvironmentGatewayConfigRow extends Migration
{
    public function up()
    {
        $rows = $this->db->table('payment_gateway_configs')
            ->where('config_key', 'environment')
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $data = ['is_sensitive' => false];

            if (! in_array($row->config_value, ['sandbox', 'production'], true)) {
                $data['config_value'] = env('ASAAS_ENV', 'sandbox');
            }

            $this->db->table('payment_gateway_configs')
                ->where('id', $row->id)
                ->update($data);
        }
    }

    public function down()
    {
        // Correção de dados: não há estado anterior válido a restaurar.
    }
}
