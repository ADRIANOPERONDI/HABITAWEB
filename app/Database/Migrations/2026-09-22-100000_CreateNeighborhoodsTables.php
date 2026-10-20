<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registro de bairros por cidade e apelidos (aliases) para o nome correto.
 *
 * `properties.bairro` continua texto — o que muda é que o valor gravado passa
 * por NeighborhoodResolver, que devolve o nome canônico registrado aqui. Assim
 * "CENTRO", "Centro" e "centro " viram uma só opção no filtro, e quando a
 * origem manda um nome diferente do bairro real ("Centro Histórico" para
 * "Centro") a associação é um alias explícito, nunca uma fusão automática.
 *
 * Chaves de cidade e de bairro são as formas sem caixa/acento
 * (NeighborhoodName::key), que é o que a unicidade precisa comparar.
 */
class CreateNeighborhoodsTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'cidade_key'     => ['type' => 'VARCHAR', 'constraint' => 120],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'normalized_key' => ['type' => 'VARCHAR', 'constraint' => 120],
            'created_at'     => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at'     => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['cidade_key', 'normalized_key'], 'uq_neighborhoods_city_key');
        $this->forge->createTable('neighborhoods');

        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'neighborhood_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'cidade_key'      => ['type' => 'VARCHAR', 'constraint' => 120],
            'alias_key'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'source'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'manual'],
            'created_at'      => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['cidade_key', 'alias_key'], 'uq_neighborhood_aliases_city_alias');
        $this->forge->addForeignKey('neighborhood_id', 'neighborhoods', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('neighborhood_aliases');
    }

    public function down()
    {
        $this->forge->dropTable('neighborhood_aliases', true);
        $this->forge->dropTable('neighborhoods', true);
    }
}
