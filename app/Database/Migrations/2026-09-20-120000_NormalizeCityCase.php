<?php

namespace App\Database\Migrations;

use App\Libraries\Text\CityName;
use App\Services\PublicPropertyVisibilityService;
use CodeIgniter\Database\Migration;

/**
 * Colapsa as grafias divergentes de `properties.cidade` na forma canônica.
 *
 * Até agora nenhum caminho de escrita normalizava a cidade: o sync do Simob
 * gravava "SÃO MIGUEL DO OESTE", o ViaCEP do formulário admin gravava
 * "São Miguel do Oeste". Como DISTINCT é sensível a caixa no Postgres, a mesma
 * cidade aparecia duas vezes no filtro da busca, e escolher uma das opções
 * escondia os imóveis gravados na outra.
 *
 * CityName agora é aplicado na gravação, então nenhuma divergência NOVA nasce.
 * Esta migration é o que arruma o que já está no banco.
 *
 * O de/para não cabe em SQL puro (Title Case com preposição em minúscula é
 * regra de PHP), então o passo é: pegar as grafias distintas, calcular a forma
 * canônica de cada uma e atualizar por grafia — um UPDATE por variante, não um
 * por imóvel.
 */
class NormalizeCityCase extends Migration
{
    public function up()
    {
        $grafias = $this->db->table('properties')
                            ->select('cidade')
                            ->distinct()
                            ->get()
                            ->getResultArray();

        foreach ($grafias as $linha) {
            $atual = $linha['cidade'];

            if ($atual === null || trim($atual) === '') {
                continue;
            }

            $canonica = CityName::normalize($atual);

            if ($canonica === $atual) {
                continue;
            }

            $this->db->table('properties')
                     ->where('cidade', $atual)
                     ->update(['cidade' => $canonica]);
        }

        // O dropdown da busca é cacheado por 1h e os pins do mapa por 30s —
        // sem isto, a cidade continuaria duplicada na tela depois da migration.
        PublicPropertyVisibilityService::invalidateCaches();
    }

    public function down()
    {
        // Irreversível de propósito: depois do up() não há como saber qual
        // imóvel estava em caixa alta e qual já estava na forma canônica — e
        // reintroduzir a divergência é exatamente o bug que esta migration
        // existe para apagar.
    }
}
