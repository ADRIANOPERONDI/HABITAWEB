<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Índice funcional para o caminho de fallback do filtro de cidade/bairro
 * (PropertyService::applyLocationFilter): quando o resolveLocationName não
 * consegue mapear a entrada para o nome exato do banco, a comparação vira
 * LOWER(cidade) = ? / LOWER(bairro) = ? — sem este índice, isso seria seq
 * scan. O caminho resolvido (nome exato) continua usando o índice composto
 * idx_properties_status_city_neighborhood já existente.
 */
class AddLowerCityNeighborhoodIndex extends Migration
{
    public function up()
    {
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS idx_properties_status_lower_city_neighborhood
             ON properties (status, LOWER(cidade), LOWER(bairro))'
        );
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS idx_properties_status_lower_city_neighborhood');
    }
}
