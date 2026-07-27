<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Duas coisas na mesma migração porque ambas tocam property_media:
 *
 * 1) `tipo` tinha três valores para a MESMA coisa, dependendo de qual caminho
 *    gravou a linha: 'FOTO' (default da migração original), 'IMAGE' (upload do
 *    painel admin) e 'imagem' (PropertyService::addMedia, usado pela API).
 *    GenerateMediaVariants.php já compensava isso com WHERE LOWER(tipo) IN (...).
 *    Padronizamos em 'IMAGE'.
 *
 * 2) `source_url` guarda a URL de origem quando a imagem foi ingerida a partir
 *    da plataforma do parceiro, e `source_url_hash` (SHA-256) é a versão
 *    indexável dela — URL crua é longa demais para índice portável entre
 *    Postgres e MySQL. É o que permite ao reimport deduplicar: se a mesma URL
 *    já foi baixada para aquele imóvel, não baixamos de novo.
 */
class NormalizePropertyMediaTipoAndAddSourceUrl extends Migration
{
    public function up()
    {
        $this->forge->addColumn('property_media', [
            'source_url' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'URL de origem quando a mídia veio de ingestão remota',
            ],
            'source_url_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'SHA-256 de source_url — usado para deduplicar reimportações',
            ],
        ]);

        $this->db->query("UPDATE property_media SET tipo = 'IMAGE' WHERE LOWER(tipo) IN ('foto', 'image', 'imagem')");

        $this->db->query('CREATE INDEX idx_media_property_source ON property_media (property_id, source_url_hash)');
    }

    public function down()
    {
        if ($this->db->DBDriver === 'Postgre') {
            $this->db->query('DROP INDEX IF EXISTS idx_media_property_source');
        } else {
            $this->db->query('DROP INDEX idx_media_property_source ON property_media');
        }

        $this->forge->dropColumn('property_media', ['source_url', 'source_url_hash']);
    }
}
