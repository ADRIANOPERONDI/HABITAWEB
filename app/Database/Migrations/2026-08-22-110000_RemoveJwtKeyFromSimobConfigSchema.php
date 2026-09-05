<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tira `jwt_key` do schema de credenciais do Simob e apaga os valores já
 * salvos.
 *
 * O campo nunca é lido: `SimobClient`/`SimobProvider` só usam `base_url` e
 * `token`. A ajuda do próprio campo já dizia que ele só serviria para
 * endpoints de pessoa/contrato/boleto, que este conector não implementa —
 * então hoje ele só confunde o tenant (mais um campo pra preencher sem
 * necessidade) e guarda, sem uso nenhum, mais uma credencial cifrada por
 * conta.
 */
class RemoveJwtKeyFromSimobConfigSchema extends Migration
{
    public function up()
    {
        $provider = $this->db->table('integration_providers')
            ->where('code', 'simob')
            ->get()
            ->getRowArray();

        if ($provider !== null) {
            $schema = json_decode($provider['config_schema'] ?? '[]', true) ?: [];
            $schema = array_values(array_filter($schema, static fn (array $field): bool => ($field['key'] ?? '') !== 'jwt_key'));

            $this->db->table('integration_providers')
                ->where('id', $provider['id'])
                ->update(['config_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE)]);
        }

        $this->db->table('account_integration_configs')
            ->where('config_key', 'jwt_key')
            ->delete();
    }

    public function down()
    {
        // Irreversível de propósito: os valores cifrados apagados no up() não
        // voltam, e reintroduzir o campo no schema sem eles só reabriria um
        // formulário vazio para uma credencial que o código nunca lê.
    }
}
