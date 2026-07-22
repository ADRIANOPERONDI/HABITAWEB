<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class MapSearchController extends BaseController
{
    use ResponseTrait;

    public function getMapData()
    {
        $filters = $this->getFiltersFromRequest();
        $propertyService = service('propertyService');
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(36, max(6, (int) ($this->request->getGet('per_page') ?? 18)));
        $mapOnly = filter_var($this->request->getGet('map_only'), FILTER_VALIDATE_BOOLEAN);
        $listOnly = filter_var($this->request->getGet('list_only'), FILTER_VALIDATE_BOOLEAN);
        
        // Remove empty filters
        $filters = array_filter($filters, function($value) {
            return !is_null($value) && $value !== '';
        });
        
        $filters['status'] = 'ACTIVE';

        $pins = $listOnly ? [] : $propertyService->searchMapPins($filters);
        $data = $mapOnly ? [
            'properties' => [],
            'pager' => null,
            'total' => count($pins),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => false,
            'next_page' => null,
        ] : $propertyService->searchMapList($filters, $perPage, $page);
        $properties = $data['properties'];
        if (!$mapOnly && !empty($properties)) {
            $this->loadCardImages($properties, 5);
        }

        // Prepare Map GeoJSON / Markers format
        $mapData = [];
        foreach ($pins as $prop) {
            if ($prop->latitude && $prop->longitude) {
                $mapData[] = [
                    'id' => $prop->id,
                    'lat' => $prop->latitude,
                    'lng' => $prop->longitude,
                    'price' => $this->formatShortPrice((float) $prop->preco),
                    'operation' => $prop->tipo_negocio,
                    'is_sponsored' => (bool) ($prop->is_destaque || ((int) ($prop->highlight_level ?? 0) > 0)),
                    'url' => site_url('imovel/' . $prop->id),
                ];
            }
        }

        // We also want to return the HTML for the sidebar list so it updates dynamically
        $listHtml = $mapOnly ? '' : view('web/partials/_property_map_list', [
            'properties' => $properties,
            'pager' => $data['pager'],
            'total' => $data['total'] ?? count($properties),
            'has_more' => $data['has_more'] ?? false,
            'next_page' => $data['next_page'] ?? null,
        ]);

        return $this->respond([
            'success' => true,
            'map_data' => $mapData,
            'list_html' => $listHtml,
            'count' => $data['total'] ?? count($properties),
            'map_count' => count($mapData),
            'page' => $data['page'] ?? $page,
            'per_page' => $data['per_page'] ?? $perPage,
            'has_more' => $data['has_more'] ?? false,
            'next_page' => $data['next_page'] ?? null,
        ]);
    }

    private function getFiltersFromRequest(): array
    {
        return [
            'tipo_negocio' => $this->request->getGet('tipo_negocio'),
            'cidade'       => $this->request->getGet('cidade'),
            'bairro'       => $this->request->getGet('bairro'),
            'tipo_imovel'  => $this->request->getGet('tipo_imovel'),
            'quartos'      => $this->request->getGet('quartos'),
            'banheiros'    => $this->request->getGet('banheiros'),
            'vagas'        => $this->request->getGet('vagas'),
            'min_price'    => $this->request->getGet('min_price'),
            'max_price'    => $this->request->getGet('max_price'),
            'bounds'       => $this->request->getGet('bounds'), // SW_LNG,SW_LAT,NE_LNG,NE_LAT
            'polygon'      => $this->request->getGet('polygon'), // JSON string [[lng,lat],...]
            'property_ids' => $this->request->getGet('property_ids'), // for filtering list by cluster
            'sort'         => $this->request->getGet('sort'),
        ];
    }

    private function formatShortPrice(float $price): string
    {
        helper('format');

        return short_price($price);
    }

    private function loadCardImages(array &$properties, int $limitPerProperty = 5): void
    {
        $ids = array_filter(array_map(static fn ($property) => (int) $property->id, $properties));
        if (empty($ids)) {
            return;
        }

        $mediaModel = model('App\Models\PropertyMediaModel');
        $medias = $mediaModel
            ->whereIn('property_id', $ids)
            ->orderBy('property_id', 'ASC')
            ->orderBy('principal', 'DESC')
            ->orderBy('ordem', 'ASC')
            ->findAll();

        $imagesByProperty = [];
        foreach ($medias as $media) {
            $propertyId = (int) $media->property_id;
            if (!isset($imagesByProperty[$propertyId])) {
                $imagesByProperty[$propertyId] = [];
            }

            if (count($imagesByProperty[$propertyId]) >= $limitPerProperty) {
                continue;
            }

            $imagesByProperty[$propertyId][] = $media->url;
        }

        foreach ($properties as $property) {
            $property->carousel_images = $imagesByProperty[(int) $property->id] ?? [];
        }
    }

    private function loadCovers(array &$properties) {
        $mediaModel = model('App\Models\PropertyMediaModel');
        $ids = array_column($properties, 'id');
        
        if (!empty($ids)) {
            $medias = $mediaModel->whereIn('property_id', $ids)->findAll();
            
            $mediaMap = [];
            $allMediasMap = [];
            
            foreach ($medias as $media) {
                if (!isset($allMediasMap[$media->property_id])) {
                    $allMediasMap[$media->property_id] = [];
                }
                $allMediasMap[$media->property_id][] = $media->url;
                
                if (!isset($mediaMap[$media->property_id]) || $media->principal) {
                    $mediaMap[$media->property_id] = $media->url;
                }
            }
    
            foreach ($properties as $property) {
                $property->cover_image = $mediaMap[$property->id] ?? null;
                $property->all_images = $allMediasMap[$property->id] ?? [];
            }
        }
    }
}
