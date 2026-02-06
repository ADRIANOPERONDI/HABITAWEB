<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        $propertyService = service('propertyService');
        $accountService = new \App\Services\AccountService();
        
        $featured = $propertyService->getFeaturedProperties(8);
        $sponsored = $propertyService->getSponsoredProperties(4);
        
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
