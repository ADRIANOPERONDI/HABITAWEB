<?php

namespace App\Services;

use App\Entities\Property;

class RankingService
{
    /**
     * Calcula o Score de Qualidade (0 a 100) para um imóvel.
     * Critérios:
     * - Fotos: +10 pontos por foto (max 50)
     * - Descrição: +10 se > 200 caracteres
     * - Endereço completo (Rua + Num): +10
     * - Características: +5 por item (max 20)
     * - Plano: Pro (+10), Gold (+20) - (Simulado por enquanto)
     */
    public function calculateScore(Property $property): int
    {
        $mediaModel = model('App\Models\PropertyMediaModel');
        $mediaCount = $mediaModel->countByProperty($property->id);
        
        $curationService = new \App\Services\CurationService();
        $result = $curationService->calculateDetailedScore($property, $mediaCount);
        $score = $result['score'];

        // 6. Promoções Ativas (Boosters de Ranking)
        $promotionModel = model('App\Models\PromotionModel');
        $activePromos = $promotionModel->where('property_id', $property->id)
                                       ->where('ativo', true)
                                       ->where('data_inicio <=', date('Y-m-d H:i:s'))
                                       ->where('data_fim >=', date('Y-m-d H:i:s'))
                                       ->findAll();

        foreach ($activePromos as $promo) {
            switch ($promo->tipo_promocao) {
                case 'SUPER_DESTAQUE':
                    $score += 1000;
                    break;
                case 'DESTAQUE':
                    $score += 500;
                    break;
                case 'VITRINE':
                    $score += 200;
                    break;
                case 'URGENTE':
                    $score += 100;
                    break;
            }
        }

        return $score;
    }

    /**
     * Atualiza o score no banco de dados.
     */
    public function updateScore(int $propertyId)
    {
        $propertyModel = model('App\Models\PropertyModel');
        $property = $propertyModel->find($propertyId);

        if (!$property) return;

        $newScore = $this->calculateScore($property);
        
        $propertyModel->update($propertyId, ['score_qualidade' => $newScore]);
    }
}
