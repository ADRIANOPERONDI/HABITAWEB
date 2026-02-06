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
        $score = 0;

        // 1. Fotos (Simulado via count no banco ou carregado na entity)
        // Para simplificar agora, vamos assumir que o controller passa o objeto carregado ou buscamos aqui.
        // Como o score costuma ser calculado no save/update, vamos fazer uma query count rápida se precisar.
        
        $db = \Config\Database::connect();
        $mediaCount = $db->table('property_media')->where('property_id', $property->id)->countAllResults();
        
        $score += min($mediaCount * 10, 50); // Max 50 pts por fotos

        // 2. Descrição
        if (strlen($property->descricao ?? '') > 200) {
            $score += 10;
        }

        // 3. Endereço
        if (!empty($property->rua) && !empty($property->numero)) {
            $score += 10;
        }

        // 4. Características (Features - Dinâmicas)
        $featureCount = $db->table('property_features')->where('property_id', $property->id)->countAllResults();
        $score += min($featureCount * 5, 20);

        // 5. Novos Campos Estáticos (Boost de completude)
        if ($property->suites > 0) $score += 5;
        if ($property->aceita_pets) $score += 2;
        if ($property->is_exclusivo) $score += 5;
        if (!empty($property->meta_title)) $score += 3;
        if ($property->is_novo) $score += 5; // Boost para imóveis novos

        // 6. Promoções Ativas (Turbo)
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

        // 7. Normalização
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
