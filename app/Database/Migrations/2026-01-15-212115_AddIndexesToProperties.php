<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesToProperties extends Migration
{
    public function up()
    {
        // Índices para Properties (Busca)
        $this->db->query("CREATE INDEX idx_properties_status ON properties(status)");
        $this->db->query("CREATE INDEX idx_properties_cidade ON properties(cidade)");
        $this->db->query("CREATE INDEX idx_properties_bairro ON properties(bairro)");
        $this->db->query("CREATE INDEX idx_properties_preco ON properties(preco)");
        $this->db->query("CREATE INDEX idx_properties_tipo_imovel ON properties(tipo_imovel)");
        
        // Índices para Media (Recuperação rápida de capa)
        // Nota: FK já cria index implícito em alguns bancos, mas vamos garantir
        $this->db->query("CREATE INDEX idx_media_property_main ON property_media(property_id, principal)");
    }

    public function down()
    {
        $this->db->query("DROP INDEX IF EXISTS idx_properties_status");
        $this->db->query("DROP INDEX IF EXISTS idx_properties_cidade");
        $this->db->query("DROP INDEX IF EXISTS idx_properties_bairro");
        $this->db->query("DROP INDEX IF EXISTS idx_properties_preco");
        $this->db->query("DROP INDEX IF EXISTS idx_properties_tipo_imovel");
        $this->db->query("DROP INDEX IF EXISTS idx_media_property_main");
    }
}
