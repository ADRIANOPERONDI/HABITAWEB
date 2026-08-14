<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class PartnerController extends BaseController
{
    protected $accountModel;
    protected $propertyModel;

    public function index()
    {
        $accountService = new \App\Services\AccountService();
        $propertyService = new \App\Services\PropertyService();
        
        $data = $accountService->listPublicPartners(12);

        // Uma query em lote (GROUP BY) para a página inteira, não uma por
        // parceiro — o loop antigo era N+1: 12 parceiros na página, 12
        // queries de contagem além da própria listagem.
        $accountIds = array_map(static fn ($partner) => $partner->id, $data['partners']);
        $counts = $propertyService->countPublicPropertiesByAccounts($accountIds);
        foreach ($data['partners'] as $partner) {
            $partner->total_properties = $counts[(int) $partner->id] ?? 0;
        }

        return view('web/partners/index', [
            'partners' => $data['partners'],
            'pager'    => $data['pager'],
            'title'    => 'Encontre uma Imobiliária ou Corretor Parceiro'
        ]);
    }

    /**
     * URL legada por id. Segue funcionando (link antigo/indexado não pode
     * quebrar), mas quando a conta já tem slug o canônico passa a ser
     * `imobiliaria/(:segment)` — 301, não 302: é o mesmo parceiro, não uma
     * mudança temporária, e é o sinal que motores de busca precisam para
     * transferir o rankeamento da URL antiga para a nova.
     */
    public function show($id)
    {
        $accountService = new \App\Services\AccountService();
        $partner = $accountService->getAccountById((int) $id);

        if (!$partner) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Parceiro não encontrado.');
        }

        if (!empty($partner->slug)) {
            return redirect()->to(site_url('imobiliaria/' . $partner->slug), 301);
        }

        return $this->renderShow($partner);
    }

    public function showBySlug(string $slug)
    {
        $accountService = new \App\Services\AccountService();
        $partner = $accountService->getAccountBySlug($slug);

        if (!$partner) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Parceiro não encontrado.');
        }

        return $this->renderShow($partner);
    }

    private function renderShow(\App\Entities\Account $partner)
    {
        $propertyService = new \App\Services\PropertyService();

        $propData = $propertyService->listPublicProperties([
            'account_id' => $partner->id,
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
