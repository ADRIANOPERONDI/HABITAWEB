<?php

namespace App\Services;

use App\Entities\Property;
use App\Models\PropertyModel;
use App\Models\SettingModel;
use CodeIgniter\I18n\Time;

class CurationService
{
    protected $propertyModel;
    protected $settingModel;

    public function __construct()
    {
        $this->propertyModel = new PropertyModel();
        $this->settingModel  = new SettingModel();
    }

    /**
     * Calculates a signature to detect duplicates.
     * Signature = md5(cep + number + floor + price + area_total)
     * Adjust components as needed for business rules.
     */
    public function calculateSignature(array $data): string
    {
        $components = [
            $data['cep'] ?? '',
            $data['numero'] ?? '',
            $data['complemento'] ?? '', // Floor often here
            // Rounding price to avoid cents diff
            round($data['preco'] ?? 0),
            $data['area_total'] ?? 0,
            $data['tipo_imovel'] ?? ''
        ];

        return md5(implode('|', $components));
    }

    /**
     * Checks if a duplicate exists.
     * @param string $signature
     * @param int|null $excludeId ID to exclude (for updates)
     * @return Property|null Returning the duplicate found or null
     */
    public function findDuplicate(string $signature, $excludeId = null)
    {
        $query = $this->propertyModel
            ->where('duplicate_signature', $signature)
            ->where('status !=', 'DELETED'); // Ignore deleted

        if ($excludeId) {
            $query->where('id !=', $excludeId);
        }

        return $query->first();
    }

    /**
     * Runs quality analysis on the property.
     * @param Property $property
     * @return array List of warnings found
     */
    public function analyzeQuality(Property $property): array
    {
        $warnings = [];

        // 1. Check Completeness
        if (empty($property->descricao) || strlen($property->descricao) < 50) {
            $warnings[] = 'description_short';
        }
        if (empty($property->area_total) && empty($property->area_construida)) {
            $warnings[] = 'no_area_info';
        }

        // 2. Check Price Suspicion (Mock logic for now)
        // Ideally: calculate avg for neighborhood/type and compare
        $avgPrice = $this->getAveragePrice($property->bairro, $property->tipo_imovel);
        if ($avgPrice > 0) {
            $variance = abs($property->preco - $avgPrice) / $avgPrice;
            if ($variance > 0.5) { // 50% variance
                $warnings[] = 'price_suspicious';
            }
        }

        return $warnings;
    }

    /**
     * Calcula média de preço real por bairro e tipo de imóvel.
     * Usa cache de 1 hora para performance.
     */
    protected function getAveragePrice($bairro, $type)
    {
        if (empty($bairro) || empty($type)) {
            return 0;
        }

        $cache = \Config\Services::cache();
        $cacheKey = "avg_price_" . md5($bairro . '_' . $type);
        
        // Tenta recuperar do cache
        $avgPrice = $cache->get($cacheKey);
        
        if ($avgPrice !== null) {
            return $avgPrice;
        }

        // Calcula média real do banco (últimos 6 meses, apenas ativos)
        $db = \Config\Database::connect();
        $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months'));
        
        $query = $db->table('properties')
            ->select('AVG(preco) as avg_price, COUNT(*) as count')
            ->where('bairro', $bairro)
            ->where('tipo_imovel', $type)
            ->where('status', 'ACTIVE')
            ->where('preco >', 0)
            ->where('created_at >=', $sixMonthsAgo)
            ->get();
        
        $result = $query->getRow();
        
        // Só considera válido se tiver pelo 3 imóveis na amostra
        if ($result && $result->count >= 3) {
            $avgPrice = round($result->avg_price);
        } else {
            $avgPrice = 0;
        }
        
        // Salva no cache por 1 hora
        $cache->save($cacheKey, $avgPrice, 3600);
        
        return $avgPrice;
    }

    /**
     * Updates the moderation status and warnings.
     */
    public function validateProperty(Property $property)
    {
        $warnings = $this->analyzeQuality($property);
        
        $property->quality_warnings = $warnings;
        $property->last_validated_at = Time::now();

        // Auto-moderation logic
        if (in_array('price_suspicious', $warnings)) {
            $property->moderation_status = 'PENDING_REVIEW';
        } else {
            $property->moderation_status = 'APPROVED';
        }
        
        // Calculate quality score (0-100)
        $property->score_qualidade = $this->calculateScore($property, $warnings);

        // $this->propertyModel->save($property); // Removed to avoid double save in PropertyService
        
        return $property->moderation_status;
    }

    /**
     * Calculate quality score with detailed breakdown.
     */
    public function calculateDetailedScore(Property $property, int $mediaCount = 0): array
    {
        $score = 0;
        $maxScore = 100;
        $breakdown = [];
        $suggestions = [];

        // 1. Basic Info (35 pts)
        if (!empty($property->titulo) && strlen($property->titulo) > 10) {
            $score += 10;
            $breakdown['Título'] = '+10';
        } else {
            $suggestions[] = "Melhore o título do anúncio (min 10 caracteres).";
        }

        if (!empty($property->descricao) && strlen($property->descricao) > 100) {
            $score += 15;
            $breakdown['Descrição Detalhada'] = '+15';
        } elseif (!empty($property->descricao)) {
            $score += 5;
            $breakdown['Descrição Simples'] = '+5';
            $suggestions[] = "Aumente a descrição para pontuar mais (min 100 caracteres).";
        } else {
            $suggestions[] = "Adicione uma descrição do imóvel.";
        }

        if (!empty($property->preco) && $property->preco > 0) {
            $score += 10;
            $breakdown['Preço Definido'] = '+10';
        }

        // 2. Specifics (35 pts)
        if (!empty($property->area_total) || !empty($property->area_construida)) {
            $score += 10;
            $breakdown['Área Informada'] = '+10';
        } else {
            $suggestions[] = "Informe a área total ou construída.";
        }

        if ($property->quartos !== null) {
            $score += 5;
            $breakdown['Qtd Quartos'] = '+5';
        }
        if ($property->suites !== null && $property->suites > 0) {
            $score += 5;
            $breakdown['Qtd Suítes'] = '+5';
        }
        if ($property->vagas !== null) {
            $score += 5;
            $breakdown['Qtd Vagas'] = '+5';
        }
        if ($property->banheiros !== null) {
            $score += 5;
            $breakdown['Qtd Banheiros'] = '+5';
        }
        if (!empty($property->cep) && !empty($property->rua)) {
            $score += 5;
            $breakdown['Endereço Completo'] = '+5';
        }

        // 3. Advanced & SEO (15 pts)
        $advancedPts = 0;
        if ($property->aceita_pets) $advancedPts += 2;
        if ($property->mobiliado || $property->semimobiliado) $advancedPts += 2;
        if ($property->is_desocupado) $advancedPts += 2;
        if ($property->is_exclusivo) $advancedPts += 4;
        
        if ($advancedPts > 0) {
            $score += $advancedPts;
            $breakdown['Extras/Diferenciais'] = "+{$advancedPts}";
        }

        if (!empty($property->meta_title) && !empty($property->meta_description)) {
            $score += 5;
            $breakdown['Otimização SEO'] = '+5';
        } else {
            $suggestions[] = "Personalize as tags de SEO para atrair mais buscas.";
        }

        // 4. Media (15 pts) - Reduced from 30 to balance new fields
        if ($mediaCount >= 10) {
            $score += 15;
            $breakdown['Fotos (10+)'] = '+15';
        } elseif ($mediaCount >= 5) {
            $score += 10;
            $breakdown['Fotos (5-9)'] = '+10';
            $suggestions[] = "Adicione 10 ou mais fotos para pontuação máxima.";
        } elseif ($mediaCount >= 1) {
            $score += 5;
            $breakdown['Fotos (1-4)'] = '+5';
            $suggestions[] = "Adicione mais fotos. Imóveis com muitas fotos recebem mais visitas.";
        } else {
            $suggestions[] = "Seu anúncio não tem fotos. Adicione fotos para aumentar a relevância.";
        }

        // Add suggestions for new fields if missing
        if ($property->suites === null || $property->suites == 0) $suggestions[] = "Informe o número de suítes.";
        if (!$property->mobiliado && !$property->semimobiliado) $suggestions[] = "Indique se o imóvel é mobiliado.";
        if (!$property->aceita_pets) $suggestions[] = "Informe se aceita animais de estimação.";

        return [
            'score'       => min($score, $maxScore),
            'breakdown'   => $breakdown,
            'suggestions' => $suggestions
        ];
    }

    /**
     * Wrapper for backward compatibility
     */
    protected function calculateScore(Property $property, array $warnings): int
    {
        // Estimate media count based on warnings or fetch real if possible
        // For simplicity in this wrapper, we assume 0 or low media if not passed
        // This wrapper is mainly used by validateProperty which runs inside Service layer
        
        $mediaCount = 0;
        // Try to count media if property ID exists
        if ($property->id) {
            $db = \Config\Database::connect();
            $mediaCount = $db->table('property_media')->where('property_id', $property->id)->countAllResults();
        }

        $result = $this->calculateDetailedScore($property, $mediaCount);
        return $result['score'];
    }
}
