<?php

namespace App\Services\Scoring;

class ScorerFactory
{
    public static function make(string $type): PropertyScorerInterface
    {
        $type = strtoupper($type);

        switch ($type) {
            case 'TERRENO':
            case 'LOTE':
                return new LandScorer();
            
            case 'COMERCIAL':
            case 'SALA':
            case 'LOJA':
                return new CommercialScorer();

            case 'GALPAO':
                return new WarehouseScorer();

            case 'APARTAMENTO':
            case 'CASA':
            case 'COBERTURA':
            case 'SOBRADO':
            default:
                return new ResidentialScorer();
        }
    }
}
