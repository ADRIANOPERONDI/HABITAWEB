<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Catálogo global de conectores de integração.
 *
 * Espelha a ideia de `payment_gateways`: a linha aqui diz QUAL classe implementa
 * o conector (`class_name`) e QUAIS campos de credencial o painel deve renderizar
 * (`config_schema`). Assim um conector novo (Vista, Ingaia, Jetimob…) entra sem
 * tocar em controller nem em view — basta a classe e uma linha nesta tabela.
 *
 * Tabela GLOBAL, sem account_id. Quem é por tenant é `account_integrations`.
 */
class CreateIntegrationProvidersTable extends Migration
{
    public function up()
    {
        $jsonType = $this->db->DBDriver === 'Postgre' ? 'JSONB' : 'JSON';

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'comment'    => 'Identificador estável usado na URL e nas FKs lógicas: simob, vista…',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'comment'    => 'Nome de exibição no painel',
            ],
            'class_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'comment'    => 'FQCN que implementa IntegrationProviderInterface',
            ],
            'is_active' => [
                'type'    => 'BOOLEAN',
                'default' => true,
                'comment' => 'Conector disponível para os tenants contratarem',
            ],
            'config_schema' => [
                'type'    => $jsonType,
                'null'    => true,
                'comment' => 'Campos de credencial que o painel renderiza (key, label, type, is_sensitive, required)',
            ],
            'capabilities' => [
                'type'    => $jsonType,
                'null'    => true,
                'comment' => 'O que o conector sabe fazer: import_properties, push_leads…',
            ],
            'docs_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');

        $this->forge->createTable('integration_providers');

        $this->seedSimob();
    }

    /**
     * Semeia o conector Simob (Flexpro Sistemas).
     *
     * Idempotente — o mesmo padrão de 20260205170500_AddStripeAndMercadoPago.
     */
    private function seedSimob(): void
    {
        $exists = $this->db->table('integration_providers')
            ->where('code', 'simob')
            ->countAllResults();

        if ($exists > 0) {
            return;
        }

        // A base URL é POR IMOBILIÁRIA (o Postman chama de {{url_imobiliaria}}),
        // então não dá para embutir aqui: é campo de credencial.
        $configSchema = [
            [
                'key'          => 'base_url',
                'label'        => 'URL da imobiliária',
                'type'         => 'url',
                'is_sensitive' => false,
                'required'     => true,
                'placeholder'  => 'https://suaimobiliaria.simob.com.br',
                'help'         => 'Endereço do seu Simob, sem barra no final.',
            ],
            [
                'key'          => 'token',
                'label'        => 'Token de integração',
                'type'         => 'password',
                'is_sensitive' => true,
                'required'     => true,
                'help'         => 'No Simob: Principal > Sistema > Configurações > aba Integrações.',
            ],
            [
                'key'          => 'jwt_key',
                'label'        => 'Chave de codificação JWT (opcional)',
                'type'         => 'password',
                'is_sensitive' => true,
                'required'     => false,
                'help'         => 'Fornecida pela Flexpro. Só é necessária para os endpoints de pessoa, contrato e boleto — não usada na sincronização de imóveis nem no envio de leads.',
            ],
        ];

        $capabilities = ['import_properties', 'push_leads'];

        $this->db->table('integration_providers')->insert([
            'code'          => 'simob',
            'name'          => 'Simob (Flexpro)',
            'class_name'    => 'App\\Libraries\\Integrations\\Simob\\SimobProvider',
            'is_active'     => true,
            'config_schema' => json_encode($configSchema, JSON_UNESCAPED_UNICODE),
            'capabilities'  => json_encode($capabilities),
            'docs_url'      => 'https://documenter.getpostman.com/view/1724124/TVRecVa8',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('integration_providers');
    }
}
