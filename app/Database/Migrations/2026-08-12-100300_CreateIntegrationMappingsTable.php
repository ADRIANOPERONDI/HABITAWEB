<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * De/para entre os IDs da plataforma externa e os campos do Habitaweb.
 *
 * Precisa ser POR TENANT, não global: no Simob os ids de categoria e de
 * característica são criados por cada imobiliária. "Dormitório(s)" é id 41 numa
 * e id 249 em outra — não existe tabela universal para embutir no código.
 *
 * O conector semeia estas linhas por casamento aproximado da descrição
 * (is_confirmed = false = "sugestão automática") e o tenant revisa na tela de
 * mapeamentos antes do primeiro sync.
 */
class CreateIntegrationMappingsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_integration_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'kind' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'comment'    => 'category (tipo de imóvel) ou characteristic (atributo)',
            ],
            'external_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'comment'    => 'ID do lado da plataforma externa',
            ],
            'external_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'comment'    => 'Descrição original, exibida na tela de mapeamento',
            ],
            'external_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'comment'    => 'Tipo do valor na origem (Simob: idTipoCaracteristica 1..5)',
            ],
            'target_field' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => true,
                'comment'    => 'Coluna de properties que recebe o valor. NULL = ignorar/anexar à descrição',
            ],
            'target_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => true,
                'comment'    => 'Valor fixo quando o de/para é de enum (ex.: categoria 17 -> tipo_imovel APARTAMENTO)',
            ],
            'is_confirmed' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'comment' => 'false = sugestão automática ainda não revisada pelo tenant',
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
        $this->forge->addUniqueKey(['account_integration_id', 'kind', 'external_id']);

        $this->forge->addForeignKey('account_integration_id', 'account_integrations', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('integration_mappings');
    }

    public function down()
    {
        $this->forge->dropTable('integration_mappings');
    }
}
