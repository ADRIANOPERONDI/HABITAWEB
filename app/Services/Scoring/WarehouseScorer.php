<?php

namespace App\Services\Scoring;

use App\Entities\Property;
use App\Helpers\PropertyCategoryHelper;

class WarehouseScorer extends BaseScorer
{
    public function calculate(Property $property, int $mediaCount = 0): array
    {
        // 1. Cálculo Base (55 pts)
        $this->calculateBaseScore($property, $mediaCount);

        // 2. Cálculo Técnico de Galpão (Total: 45 pts)
        
        // Área Total é CRÍTICO para galpões (15 pts)
        if (PropertyCategoryHelper::isFieldApplicable('GALPAO', 'area_total')) {
            if (!empty($property->area_total) && $property->area_total > 0) {
                $this->addScore(15, 'Área Total Informada', 'Técnico');
            } else {
                $this->suggestions[] = "Informe a área total do galpão (área do terreno).";
            }
        }

        // Área Construída (Pé direito/Livre) é muito importante (10 pts)
        if (PropertyCategoryHelper::isFieldApplicable('GALPAO', 'area_construida')) {
            if (!empty($property->area_construida) && $property->area_construida > 0) {
                $this->addScore(10, 'Área Construída Informada', 'Técnico');
            } else {
                $this->suggestions[] = "Informe a área construída do galpão.";
            }
        }

        // Vagas (Carga/Descarga e pátio) (10 pts)
        if (PropertyCategoryHelper::isFieldApplicable('GALPAO', 'vagas')) {
            if ($property->vagas !== null && $property->vagas >= 0) {
                $this->addScore(10, 'Pátio/Vagas de Garagem', 'Técnico');
                if ($property->vagas == 0) {
                    $this->suggestions[] = "Especifique se existe área de carga/descarga ou vagas.";
                }
            }
        }

        // Banheiros/Vestiários (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable('GALPAO', 'banheiros')) {
            if ($property->banheiros !== null && $property->banheiros >= 0) {
                $this->addScore(5, 'Banheiros / Vestiários', 'Técnico');
            }
        }

        // Endereço / Localização Logística (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable('GALPAO', 'cep')) {
            if (!empty($property->cep) && !empty($property->rua) && !empty($property->cidade)) {
                $this->addScore(5, 'Localização Logística', 'Técnico');
            } else {
                $this->suggestions[] = "O endereço logístico é vital para galpões. Preencha-o.";
            }
        }

        return $this->result();
    }
}
