<?php

namespace App\Services\Scoring;

use App\Entities\Property;

interface PropertyScorerInterface
{
    /**
     * Calcula o score detalhado para um imóvel.
     * 
     * @param Property $property
     * @param int $mediaCount
     * @return array ['score' => int, 'breakdown' => array, 'suggestions' => array]
     */
    public function calculate(Property $property, int $mediaCount = 0): array;
}
