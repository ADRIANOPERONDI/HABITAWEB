<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class PromotionController extends BaseController
{
    /**
     * Lista todas as promoções (Dashboard Destaques)
     */
    public function index()
    {
        $promotionModel = model('App\Models\PromotionModel');
        $user = auth()->user();

        $builder = $promotionModel->select('promotions.*, properties.titulo, properties.id as property_id')
                                  ->join('properties', 'properties.id = promotions.property_id');
        
        // Se user tem conta e não é superadmin/admin, filtra
        if ($user && $user->account_id && !$user->inGroup('superadmin', 'admin')) {
            $builder->where('properties.account_id', $user->account_id);
        }

        $promotions = $builder->orderBy('promotions.created_at', 'DESC')
                              ->findAll();

        return view('Admin/promotions/list', ['promotions' => $promotions]);
    }

    /**
     * Tela de Turbinar um Imóvel Específico
     */
    /**
     * Regra única de acesso ao turbo de um imóvel: staff da plataforma ou dono.
     *
     * Antes `turbo()` aceitava superadmin|admin|dono e `store()` só
     * superadmin|dono. Um admin da plataforma abria a tela de um imóvel de
     * terceiro e levava "Acesso negado" ao submeter.
     *
     * @return object|null O imóvel autorizado, ou null se não existe/não pode.
     */
    private function authorizedProperty($propertyId): ?object
    {
        $property = model('App\Models\PropertyModel')->find($propertyId);

        if (!$property) {
            return null;
        }

        $user    = auth()->user();
        $isStaff = $user->inGroup('superadmin') || $user->inGroup('admin');

        if (!$isStaff && $property->account_id != $user->account_id) {
            return null;
        }

        return $property;
    }

    public function turbo($propertyId)
    {
        $property = $this->authorizedProperty($propertyId);

        if (!$property) {
            return redirect()->back()->with('error', 'Imóvel não encontrado ou acesso negado.');
        }

        // 2. Carrega Pacotes e Promoções Ativas
        $promotionService = service('promotionService');
        $packages = $promotionService->listPackages();

        $promotionModel = model('App\Models\PromotionModel');
        $activePromos = $promotionModel->where('property_id', $propertyId)
                                       ->where('ativo', true)
                                       ->findAll();

        return view('Admin/promotions/index', [
            'property' => $property,
            'packages' => $packages,
            'activePromos' => $activePromos,
            // Cota mensal de turbinada incluída no plano (Fase 1/C8) — sem
            // isto, a única forma de turbinar era sempre pagar avulso, mesmo
            // pra quem já tem turbinadas sobrando no plano.
            'quota' => service('turboService')->quotaFor((int) $property->account_id),
        ]);
    }

    public function store($propertyId)
    {
        $property = $this->authorizedProperty($propertyId);

        if (!$property) {
            return redirect()->back()->with('error', 'Imóvel não encontrado ou acesso negado.');
        }

        $packageKey = $this->request->getPost('package_key');

        if (!$packageKey) {
            return redirect()->back()->with('error', 'Selecione um pacote.');
        }

        // 2. Aplica Promoção (Gera Pagamento)
        $promotionService = service('promotionService');
        $result = $promotionService->applyPackage($propertyId, $packageKey);

        if ($result['success']) {
            $promotionPackageModel = model('App\Models\PromotionPackageModel');
            $package = $promotionPackageModel->where('chave', $packageKey)->first();

            return view('Admin/promotions/checkout', [
                'property'    => $property,
                'package'     => $package,
                'invoice_url' => $result['invoice_url'],
                'payment_id'  => $result['payment_id']
            ]);
        }

        return redirect()->back()->with('error', $result['message']);
    }
    /**
     * POST: usa uma turbinada da cota mensal do plano — sem passar pelo
     * gateway, ao contrário de `store()`. `TurboService::activateFromQuota`
     * já faz toda a checagem (cota restante, trava de concorrência); esta
     * ação só resolve o imóvel autorizado e traduz o resultado pro flash.
     */
    public function useQuota($propertyId)
    {
        $property = $this->authorizedProperty($propertyId);

        if (!$property) {
            return redirect()->back()->with('error', 'Imóvel não encontrado ou acesso negado.');
        }

        $result = service('turboService')->activateFromQuota((int) $propertyId, (int) $property->account_id);

        return redirect()->back()->with($result['success'] ? 'message' : 'error', $result['message']);
    }

    public function checkStatus($paymentId)
    {
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $transaction = $transactionModel->where('gateway_transaction_id', $paymentId)->first();

        if (!$transaction) {
            return $this->response->setJSON(['status' => 'NOT_FOUND']);
        }

        return $this->response->setJSON([
            'status' => $transaction['status'],
            'confirmed' => ($transaction['status'] === 'CONFIRMED' || $transaction['status'] === 'PAID')
        ]);
    }
}
