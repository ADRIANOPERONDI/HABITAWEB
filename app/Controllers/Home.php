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

        // Patrocinados: o POOL é cacheado (300s), mas o sorteio acontece a cada
        // requisição — mantém a rotação visual que o antigo ORDER BY RANDOM()
        // dava, sem pagar a query (RANDOM() é incacheável e força sort completo).
        $sponsoredPool = cache()->get('home_sponsored_pool');
        if ($sponsoredPool === null) {
            $sponsoredPool = $propertyService->getSponsoredPool(12);
            cache()->save('home_sponsored_pool', $sponsoredPool, 300);
        }
        shuffle($sponsoredPool);
        $sponsored = array_slice($sponsoredPool, 0, 4);
        
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
            'sponsoredProperties' => $sponsored,
            'partners'           => $partners,
            'cidades'            => $filterOptions['cidades'],
            'bairros'            => $filterOptions['bairros'],
            'tipos'              => $filterOptions['tipos']
        ]);
    }
}
