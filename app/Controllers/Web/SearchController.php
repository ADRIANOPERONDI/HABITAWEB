<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class SearchController extends BaseController
{
    public function index()
    {
        $filters = $this->getFiltersFromRequest();
        return $this->executeSearch($filters);
    }

    public function searchOne($segment1)
    {
        // Ex: /imoveis/venda
        $filters = $this->getFiltersFromRequest();
        $filters['tipo_negocio'] = $this->mapTransactionType($segment1);
        
        return $this->executeSearch($filters);
    }

    public function searchTwo($segment1, $segment2)
    {
        // Ex: /imoveis/venda/sao-paulo
        $filters = $this->getFiltersFromRequest();
        $filters['tipo_negocio'] = $this->mapTransactionType($segment1);
        $filters['cidade']       = $this->unslugify($segment2);

        return $this->executeSearch($filters);
    }

    public function searchThree($segment1, $segment2, $segment3)
    {
        // Ex: /imoveis/venda/sao-paulo/centro
        $filters = $this->getFiltersFromRequest();
        $filters['tipo_negocio'] = $this->mapTransactionType($segment1);
        $filters['cidade']       = $this->unslugify($segment2);
        $filters['bairro']       = $this->unslugify($segment3);

        return $this->executeSearch($filters);
    }

    private function executeSearch(array $filters)
    {
        $propertyService = service('propertyService');
        
        // Remove filtros vazios
        $filters = array_filter($filters, function($value) {
            return !is_null($value) && $value !== '';
        });
        
        // Força apenas ativos
        $filters['status'] = 'ACTIVE';

        // 1. Busca Resultados da Pesquisa Principal
        $data = $propertyService->listProperties($filters, 12);
        
        // 2. Busca Destaques/Turbo (Promoted Carousel) - Respeitando filtros
        $promotedFilters = $filters; // Copy existing search filters
        $promotedFilters['promoted_only'] = true; // Add promoted constraint
        $promotedData = $propertyService->listProperties($promotedFilters, 10); // Limit 10 for carousel
        $promotedProperties = $promotedData['properties'];

        // Carrega capas (Main List)
        if (!empty($data['properties'])) {
             $this->loadCovers($data['properties']);
        }
        
        // Carrega capas (Promoted Carousel)
        if (!empty($promotedProperties)) {
            $this->loadCovers($promotedProperties);
        }

        return view('web/search', [
            'properties'         => $data['properties'],
            'promotedProperties' => $promotedProperties,
            'pager'              => $data['pager'],
            'filters'            => $filters
        ]);
    }

    private function loadCovers(array &$properties) {
             $mediaModel = model('App\Models\PropertyMediaModel');
             $ids = array_column($properties, 'id');
             
             if (!empty($ids)) {
                 $medias = $mediaModel->whereIn('property_id', $ids)->findAll();
                 
                 $mediaMap = [];
                 foreach ($medias as $media) {
                     if (!isset($mediaMap[$media->property_id]) || $media->principal) {
                         $mediaMap[$media->property_id] = $media->url;
                     }
                 }
         
                 foreach ($properties as $property) {
                     $property->cover_image = $mediaMap[$property->id] ?? null;
                 }
             }
    }

    // Helper antigo para compatibilidade de diff (remove se duplicate)
    private function _unused_executeSearch(array $filters)
    {
        // ... (original content logic replaced above)
    }

    private function getFiltersFromRequest(): array
    {
        return [
            'tipo_negocio' => $this->request->getGet('tipo_negocio'),
            'cidade'       => $this->request->getGet('cidade'),
            'bairro'       => $this->request->getGet('bairro'),
            'tipo_imovel'  => $this->request->getGet('tipo_imovel'),
            'min_price'    => $this->request->getGet('min_price'),
            'max_price'    => $this->request->getGet('max_price'),
        ];
    }

    private function mapTransactionType($segment)
    {
        $segment = strtolower($segment);
        if ($segment === 'venda' || $segment === 'comprar') return 'VENDA';
        if ($segment === 'aluguel' || $segment === 'alugar') return 'ALUGUEL';
        return strtoupper($segment);
    }

    private function unslugify($string)
    {
        // Simples: substitui traço por espaço. 
        // Melhora futura: banco de s e acentuação correta.
        return str_replace('-', ' ', urldecode($string));
    }
}
