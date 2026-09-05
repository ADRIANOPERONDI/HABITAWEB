<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Séries diárias agregadas para o painel por período (Fase 4).
 *
 * Nada de linha crua: Redis acumula por dia (`RedisMetricsBuffer`), o cron
 * `metrics:flush` faz UPSERT aqui. Cardinalidade é limitada por construção —
 * um `search_events` cru com o volume de AJAX do mapa (pan/zoom a cada
 * arrasto) seria ordens de grandeza maior e não traria informação nova.
 *
 * `property_view_daily`: total de views + views únicas por imóvel/dia.
 * "Antes/depois de turbinar" não precisa de captura nova — é este dado
 * cruzado com `promotions.data_inicio/data_fim`; foi para isso que a
 * granularidade diária foi escolhida.
 *
 * `property_view_source_daily`: mesma série, quebrada por origem
 * (heurística simples sobre o Referer — DIRETO/BUSCA/REDES_SOCIAIS/OUTRO).
 *
 * `search_daily`: uma busca vira uma linha por (dia, tipo_negocio, cidade,
 * bairro, tipo_imovel, faixa_preco) — os filtros semânticos bucketizados,
 * nunca coordenadas de mapa cruas.
 */
class CreateMetricsDailyTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'property_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'dia'         => ['type' => 'DATE'],
            'views'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'views_unicas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'  => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at'  => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['property_id', 'dia']);
        $this->forge->addKey('dia');
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('property_view_daily');

        $this->forge->addField([
            'property_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'dia'         => ['type' => 'DATE'],
            'origem'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'views'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'  => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at'  => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['property_id', 'dia', 'origem']);
        $this->forge->addKey('dia');
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('property_view_source_daily');

        $this->forge->addField([
            'dia'          => ['type' => 'DATE'],
            'tipo_negocio' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => ''],
            'cidade'       => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'bairro'       => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'tipo_imovel'  => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => ''],
            'faixa_preco'  => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => ''],
            'buscas'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_at'   => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at'   => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['dia', 'tipo_negocio', 'cidade', 'bairro', 'tipo_imovel', 'faixa_preco']);
        $this->forge->addKey('dia');
        $this->forge->addKey(['cidade', 'bairro']);
        $this->forge->createTable('search_daily');
    }

    public function down()
    {
        $this->forge->dropTable('search_daily');
        $this->forge->dropTable('property_view_source_daily');
        $this->forge->dropTable('property_view_daily');
    }
}
