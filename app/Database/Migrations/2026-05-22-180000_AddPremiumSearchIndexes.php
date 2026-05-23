<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPremiumSearchIndexes extends Migration
{
    public function up()
    {
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_properties_status_geo ON properties(status, latitude, longitude)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_properties_status_business_price ON properties(status, tipo_negocio, preco)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_properties_status_city_neighborhood ON properties(status, cidade, bairro)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_property_cover_order ON property_media(property_id, principal, ordem)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS idx_properties_status_geo');
        $this->db->query('DROP INDEX IF EXISTS idx_properties_status_business_price');
        $this->db->query('DROP INDEX IF EXISTS idx_properties_status_city_neighborhood');
        $this->db->query('DROP INDEX IF EXISTS idx_media_property_cover_order');
    }
}
