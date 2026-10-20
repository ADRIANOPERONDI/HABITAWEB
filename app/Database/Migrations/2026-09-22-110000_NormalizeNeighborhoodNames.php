<?php

namespace App\Database\Migrations;

use App\Services\NeighborhoodResolver;
use CodeIgniter\Database\Migration;

/**
 * Unifica as grafias de `properties.bairro` já gravadas ("CENTRO" / "Centro").
 *
 * Mesma ideia de NormalizeCityCase: a gravação nova já passa por
 * NeighborhoodResolver, esta migration arruma o que veio antes. A lógica vive
 * em NeighborhoodResolver::backfill() — a mesma que `spark
 * imoveis:normalizar-bairros --dry-run` usa para mostrar o que mudaria.
 * Agrupa por cidade + bairro, nunca funde nomes distintos.
 */
class NormalizeNeighborhoodNames extends Migration
{
    public function up()
    {
        (new NeighborhoodResolver($this->db))->backfill(false);
    }

    public function down()
    {
        // Irreversível de propósito, pelo mesmo motivo de NormalizeCityCase:
        // depois do up() não há como saber qual grafia cada imóvel tinha.
    }
}
