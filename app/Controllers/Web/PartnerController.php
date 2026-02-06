<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\AccountModel;
use App\Models\PropertyModel;

class PartnerController extends BaseController
{
    protected $accountModel;
    protected $propertyModel;

    public function index()
    {
        $accountService = new \App\Services\AccountService();
        $propertyService = new \App\Services\PropertyService();
        
        $data = $accountService->listPublicPartners(12);
        
        // Calculate total properties for each partner via service/model logic
        foreach ($data['partners'] as $partner) {
            $partner->total_properties = $propertyService->countActivePropertiesByAccount($partner->id);
        }

        return view('web/partners/index', [
            'partners' => $data['partners'],
            'pager'    => $data['pager'],
            'title'    => 'Encontre uma Imobiliária ou Corretor Parceiro'
        ]);
    }

    /**
     * Show partner profile and their properties
     */
    public function show($id)
    {
        $accountService = new \App\Services\AccountService();
        $propertyService = new \App\Services\PropertyService();
        
        $partner = $accountService->getAccountById((int)$id);

        if (!$partner) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Parceiro não encontrado.');
        }

        // Get properties for this partner via service
        $propData = $propertyService->listProperties([
            'account_id' => $id,
            'status'     => 'ACTIVE'
        ], 9);

        return view('web/partners/show', [
            'partner'    => $partner,
            'properties' => $propData['properties'],
            'pager'      => $propData['pager'],
            'title'      => $partner->nome . ' - Perfil do Parceiro'
        ]);
    }
}
