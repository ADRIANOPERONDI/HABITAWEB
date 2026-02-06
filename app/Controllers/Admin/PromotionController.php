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

        return view('admin/promotions/list', ['promotions' => $promotions]);
    }

    /**
     * Tela de Turbinar um Imóvel Específico
     */
    public function turbo($propertyId)
    {
        // 1. Verifica Propriedade e Dono
        $propertyModel = model('App\Models\PropertyModel');
        $property = $propertyModel->find($propertyId);

        if (!$property) {
            return redirect()->back()->with('error', 'Imóvel não encontrado.');
        }

        $user = auth()->user();
        // Permite se for SuperAdmin ou se for o dono da conta
        $isAdmin = $user->inGroup('superadmin') || $user->inGroup('admin');
        if (!$isAdmin && $property->account_id != $user->account_id) {
             return redirect()->back()->with('error', 'Acesso negado.');
        }

        // 2. Carrega Pacotes e Promoções Ativas
        $promotionService = service('promotionService');
        $packages = $promotionService->listPackages();
        
        $promotionModel = model('App\Models\PromotionModel');
        $activePromos = $promotionModel->where('property_id', $propertyId)
                                       ->where('ativo', true)
                                       ->findAll();

        return view('admin/promotions/index', [
            'property' => $property,
            'packages' => $packages,
            'activePromos' => $activePromos
        ]);
    }

    public function store($propertyId)
    {
        // 1. Verifica Propriedade e Dono (Mesma checagem)
        $propertyModel = model('App\Models\PropertyModel');
        $property = $propertyModel->find($propertyId);

        if (!$property) {
            return redirect()->back()->with('error', 'Imóvel não encontrado.');
        }

        $user = auth()->user();
        if (!$user->inGroup('superadmin') && $property->account_id != $user->account_id) {
             return redirect()->back()->with('error', 'Acesso negado.');
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

            return view('admin/promotions/checkout', [
                'property'    => $property,
                'package'     => $package,
                'invoice_url' => $result['invoice_url'],
                'payment_id'  => $result['payment_id']
            ]);
        }

        return redirect()->back()->with('error', $result['message']);
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
