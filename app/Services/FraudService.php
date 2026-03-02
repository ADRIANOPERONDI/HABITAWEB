<?php

namespace App\Services;

use App\Entities\Property;
use App\Models\PropertyModel;
use CodeIgniter\Config\Factories;

class FraudService
{
    protected PropertyModel $propertyModel;

    public function __construct()
    {
        $this->propertyModel = Factories::models(PropertyModel::class);
    }

    /**
     * Analisa um imóvel em busca de sinais de fraude.
     * 
     * @param Property $property
     * @return array Lista de flags de fraude encontradas.
     */
    public function scan(Property $property): array
    {
        $flags = [];

        // 1. Descrições Duplicadas em Contas Diferentes
        if ($this->hasDuplicateDescription($property)) {
            $flags[] = 'duplicate_description_other_account';
        }

        // 2. Palavras Suspeitas (Heurística de Scams)
        if ($this->hasSuspiciousKeywords($property)) {
            $flags[] = 'suspicious_keywords';
        }

        // 3. Fotos Duplicadas (Heurística Simples)
        if ($this->hasDuplicatePhotos($property)) {
            $flags[] = 'duplicate_photos_detected';
        }

        return $flags;
    }

    /**
     * Verifica se a descrição é idêntica à de outro imóvel de uma conta diferente.
     */
    protected function hasDuplicateDescription(Property $property): bool
    {
        if (empty($property->descricao) || strlen($property->descricao) < 100) {
            return false;
        }

        $duplicate = $this->propertyModel
            ->where('descricao', $property->descricao)
            ->where('account_id !=', $property->account_id)
            ->where('status !=', 'DELETED')
            ->first();

        return $duplicate !== null;
    }

    /**
     * Verifica a presença de palavras comumente usadas em golpes imobiliários.
     */
    protected function hasSuspiciousKeywords(Property $property): bool
    {
        $keywords = [
            'depósito antecipado',
            'reserva imediata',
            'pagamento adiantado',
            'urgente motivo viagem',
            'transferência imediata',
            'valor abaixo do mercado',
            'oportunidade única urgente',
            'sem fiador sem comprovação'
        ];

        $content = mb_strtolower($property->titulo . ' ' . $property->descricao);
        
        foreach ($keywords as $word) {
            if (mb_strpos($content, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heurística básica para detectar fotos duplicadas.
     * (Pode ser expandida no futuro com hash de imagem).
     */
    protected function hasDuplicatePhotos(Property $property): bool
    {
        if (!$property->id) return false;

        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $currentMedia = $mediaModel->where('property_id', $property->id)->findAll();

        if (empty($currentMedia)) return false;

        foreach ($currentMedia as $media) {
            $filename = basename($media->url);
            
            // Procura esse mesmo nome de arquivo em outros imóveis (excluindo o atual)
            $otherMedia = $mediaModel->builder()
                ->like('url', '/' . $filename)
                ->where('property_id !=', $property->id)
                ->limit(1)
                ->get()
                ->getRow();

            if ($otherMedia) {
                return true;
            }
        }

        return false;
    }
}
