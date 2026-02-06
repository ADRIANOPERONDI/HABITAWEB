<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class PropertyDetailsController extends BaseController
{
    public function show($id)
    {
        $propertyService = service('propertyService');
        
        // Incrementa contador de visitas
        $propertyService->incrementVisit($id);

        $data = $propertyService->getPropertyDetails($id);

        if (!$data) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound("Imóvel não encontrado: $id");
        }

        return view('web/property_details', $data);
    }
}
