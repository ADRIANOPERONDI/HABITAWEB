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
        $resultData = $this->propertyModel->getAveragePriceForNeighborhood($bairro, $type);
        
        // Só considera válido se tiver pelo 3 imóveis na amostra
        if ($resultData['count'] >= 3) {
            $avgPrice = round($resultData['avg_price']);
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
        $scorer = \App\Services\Scoring\ScorerFactory::make($property->tipo_imovel ?? 'APARTAMENTO');
        return $scorer->calculate($property, $mediaCount);
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
        // Try to count media if property ID exists
        if ($property->id) {
            $mediaModel = model('App\Models\PropertyMediaModel');
            $mediaCount = $mediaModel->countByProperty($property->id);
        }

        $result = $this->calculateDetailedScore($property, $mediaCount);
        return $result['score'];
    }
}
