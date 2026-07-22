<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        $propertyService = service('propertyService');
        $accountService = new \App\Services\AccountService();

        // Destaques cacheados (180s): a home é a página mais acessada e esta é
        // a query mais cara dela (sort ponderado com joins de plano/assinatura).
        $featured = cache()->get('home_featured');
        if ($featured === null) {
            $featured = $propertyService->getFeaturedProperties(8);
            cache()->save('home_featured', $featured, 180);
        }

        // Imóveis do mapa do hero: pool de destaques pagos (substitui a antiga
        // seção "Patrocinados" em cards — o mapa já cobre esse papel, mostrando
        // o máximo de destaques pagos disponíveis). Só cai para os NÃO pagos
        // (ordenados por score) se não houver NENHUM pago com coordenadas;
        // nunca mistura os dois grupos na mesma exibição.
        $mapSponsoredPool = cache()->get('home_map_sponsored_pool');
        if ($mapSponsoredPool === null) {
            $mapSponsoredPool = $propertyService->getSponsoredPool(200);
            cache()->save('home_map_sponsored_pool', $mapSponsoredPool, 300);
        }
        $mapProperties = $this->onlyWithCoords($mapSponsoredPool);

        if (empty($mapProperties)) {
            $mapNonPaidPool = cache()->get('home_map_nonpaid_pool');
            if ($mapNonPaidPool === null) {
                $mapNonPaidPool = $propertyService->getNonPaidProperties(200);
                cache()->save('home_map_nonpaid_pool', $mapNonPaidPool, 300);
            }
            $mapProperties = $this->onlyWithCoords($mapNonPaidPool);
        }
        $mapPins = $this->buildMapPins($mapProperties);

        // Busca Parceiros (Cache de 1 hora)
        $partners = cache()->get('home_partners');
        if ($partners === null) {
             $partners = $accountService->getFeaturedPartners(12);
             cache()->save('home_partners', $partners, 3600);
        }

        // Busca Opções de Filtro (Cidades, Bairros, Tipos) - Cache de 1 hora
        $filterOptions = cache()->get('home_filter_options');
        if ($filterOptions === null) {
            $filterOptions = $propertyService->getSearchFilterOptions();
            cache()->save('home_filter_options', $filterOptions, 3600);
        }

        return view('web/home', [
            'featuredProperties' => $featured,
            'mapPins'            => $mapPins,
            'mapProperties'      => $mapProperties,
            'partners'           => $partners,
            'cidades'            => $filterOptions['cidades'],
            'bairros'            => $filterOptions['bairros'],
            'tipos'              => $filterOptions['tipos']
        ]);
    }

    /**
     * Filtra apenas os imóveis que possuem coordenadas (aparecem no mapa).
     *
     * @param array $properties Entidades App\Entities\Property
     * @return array
     */
    private function onlyWithCoords(array $properties): array
    {
        return array_values(array_filter($properties, static function ($property) {
            return !empty($property->latitude) && !empty($property->longitude);
        }));
    }

    /**
     * Converte entidades Property em pins de mapa no mesmo formato do
     * MapSearchController::getMapData().
     *
     * @param array $properties Entidades App\Entities\Property (já filtradas por onlyWithCoords)
     * @return array<int, array<string, mixed>>
     */
    private function buildMapPins(array $properties): array
    {
        helper('format');

        $pins = [];
        foreach ($properties as $property) {
            if (empty($property->latitude) || empty($property->longitude)) {
                continue;
            }

            $pins[] = [
                'id'           => $property->id,
                'lat'          => (float) $property->latitude,
                'lng'          => (float) $property->longitude,
                'price'        => short_price((float) $property->preco),
                'operation'    => $property->tipo_negocio,
                'is_sponsored' => (bool) ($property->is_destaque || ((int) ($property->highlight_level ?? 0) > 0)),
                'url'          => site_url('imovel/' . $property->id),
            ];
        }

        return $pins;
    }
}
