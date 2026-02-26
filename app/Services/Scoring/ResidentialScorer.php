<?php

namespace App\Services\Scoring;

use App\Entities\Property;
use App\Helpers\PropertyCategoryHelper;

class ResidentialScorer extends BaseScorer
{
    public function calculate(Property $property, int $mediaCount = 0): array
    {
        // 1. Cálculo Base (55 pts)
        $this->calculateBaseScore($property, $mediaCount);

        // Área (10 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'area_total') || PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'area_construida')) {
            if (!empty($property->area_total) || !empty($property->area_construida)) {
                $this->addScore(10, 'Área Informada', 'Técnico');
            } else {
                $this->suggestions[] = "Informe a área do imóvel.";
            }
        }

        // Quartos (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'quartos')) {
            if ($property->quartos !== null && $property->quartos >= 0) {
                $this->addScore(5, 'Dormitórios', 'Técnico');
            } else {
                $this->suggestions[] = "Informe a quantidade de dormitórios.";
            }
        }

        // Suítes (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'suites')) {
            if ($property->suites !== null && $property->suites >= 0) {
                $this->addScore(5, 'Suítes', 'Técnico');
                if ($property->suites == 0) {
                    $this->suggestions[] = "Informe se o imóvel possui suítes.";
                }
            }
        }

        // Banheiros (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'banheiros')) {
            if ($property->banheiros !== null && $property->banheiros >= 0) {
                $this->addScore(5, 'Banheiros', 'Técnico');
            }
        }

        // Vagas (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'vagas')) {
            if ($property->vagas !== null && $property->vagas >= 0) {
                $this->addScore(5, 'Vagas de Garagem', 'Técnico');
            }
        }

        // Endereço (5 pts)  - CEP & Rua always applicable via base but testing to be safe
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'cep')) {
            if (!empty($property->cep) && !empty($property->rua)) {
                $this->addScore(5, 'Endereço Completo', 'Técnico');
            }
        }

        // Diferenciais (10 pts)
        $extraPts = 0;
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'aceita_pets') && $property->aceita_pets) $extraPts += 3;
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'mobiliado') && ($property->mobiliado || $property->semimobiliado)) $extraPts += 4;
        if ($property->is_exclusivo) $extraPts += 3;

        if ($extraPts > 0) {
            $this->addScore($extraPts, 'Diferenciais/Exclusividade', 'Extras');
        }

        return $this->result();
    }
}
