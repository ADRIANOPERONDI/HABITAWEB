<?php

namespace App\Services\Scoring;

use App\Entities\Property;
use App\Helpers\PropertyCategoryHelper;

class LandScorer extends BaseScorer
{
    public function calculate(Property $property, int $mediaCount = 0): array
    {
        // 1. Cálculo Base (55 pts)
        $this->calculateBaseScore($property, $mediaCount);

        // Área Total (15 pts) - Crucial para terrenos
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'area_total')) {
            if (!empty($property->area_total) && $property->area_total > 0) {
                $this->addScore(15, 'Área Total Informada', 'Técnico');
            } else {
                $this->suggestions[] = "Para terrenos, informar a área total é o fator mais importante.";
            }
        }

        // Endereço / Localização (15 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'cep')) {
            if (!empty($property->cep) && !empty($property->rua)) {
                $this->addScore(15, 'Endereço Identificado', 'Técnico');
            } else {
                $this->suggestions[] = "Identifique a rua/loteamento do terreno.";
            }
        }

        // Exclusividade e Investidor
        if ($property->is_exclusivo) {
            $this->addScore(5, 'Exclusividade', 'Extras');
        }

        // Geolocalização Precisa (10 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'latitude')) {
            if (!empty($property->latitude) && !empty($property->longitude)) {
                $this->addScore(10, 'Geolocalização Precisa (Mapa)', 'Extras');
            } else {
                $this->suggestions[] = "Marque a localização exata no mapa. Terrenos sem mapa perdem engajamento.";
            }
        }

        return $this->result();
    }
}
