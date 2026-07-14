<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class PropertyDetailsController extends BaseController
{
    public function show($id)
    {
        $propertyService = service('propertyService');
        
        $data = $propertyService->getPublicPropertyDetails((int) $id);

        if (!$data) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound("Imóvel não encontrado: $id");
        }

        $propertyService->incrementVisit((int) $id);

        // isFavorited já vem de getPropertyDetails() (mesma checagem, mesmo
        // auth()->id()) — o bloco duplicado que existia aqui fazia uma segunda
        // query idêntica por page view de usuário logado.

        return view('web/property_details', $data);
    }
}
