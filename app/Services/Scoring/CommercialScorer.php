<?php

namespace App\Services\Scoring;

use App\Entities\Property;
use App\Helpers\PropertyCategoryHelper;

class CommercialScorer extends BaseScorer
{
    public function calculate(Property $property, int $mediaCount = 0): array
    {
        // 1. Cálculo Base (55 pts)
        $this->calculateBaseScore($property, $mediaCount);

        // 2. Cálculo Técnico Comercial (Total: 45 pts)
        
        // Área Construída/Útil é nobre para comerciais (15 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'area_construida')) {
            if (!empty($property->area_construida) && $property->area_construida > 0) {
                $this->addScore(15, 'Área Construída Informada', 'Técnico');
            } elseif (!empty($property->area_total)) {
                $this->addScore(10, 'Área Total Informada', 'Técnico');
                $this->suggestions[] = "Informe a área construída para uma melhor avaliação comercial.";
            } else {
                $this->suggestions[] = "Informe a área do imóvel comercial.";
            }
        }

        // Vagas de Garagem (Crucial para comercial) (10 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'vagas')) {
            if ($property->vagas !== null && $property->vagas >= 0) {
                $this->addScore(10, 'Vagas de Estacionamento', 'Técnico');
                if ($property->vagas == 0) {
                    $this->suggestions[] = "Informe se há vagas para clientes/ funcionários.";
                }
            }
        }

        // Banheiros (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'banheiros')) {
            if ($property->banheiros !== null && $property->banheiros >= 0) {
                $this->addScore(5, 'Banheiros / Sanitários', 'Técnico');
            }
        }

        // Endereço (10 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'cep')) {
            if (!empty($property->cep) && !empty($property->rua)) {
                $this->addScore(10, 'Ponto Comercial Identificado', 'Técnico');
            } else {
                $this->suggestions[] = "O endereço exato é vital para imóveis comerciais.";
            }
        }

        // Indicado para Investidor (5 pts)
        if (PropertyCategoryHelper::isFieldApplicable($property->tipo_imovel, 'indicado_investidor')) {
            if ($property->indicado_investidor) {
                $this->addScore(5, 'Perfil Investidor', 'Extras');
            }
        }

        return $this->result();
    }
}
