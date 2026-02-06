<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class CouponsController extends BaseController
{
    protected $couponModel;

    public function __construct()
    {
        $this->couponModel = model('App\Models\CouponModel');
    }

    /**
     * Listagem de cupons
     */
    public function index()
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $coupons = $this->couponModel->orderBy('created_at', 'DESC')->findAll();

        return view('admin/coupons/index', [
            'coupons' => $coupons
        ]);
    }

    /**
     * Formulário de criação
     */
    public function create()
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        return view('admin/coupons/form', [
            'coupon' => null,
            'action' => 'create'
        ]);
    }

    /**
     * Salvar cupom
     */
    public function store()
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $rules = [
            'code' => 'required|min_length[3]|max_length[50]|is_unique[coupons.code]',
            'discount_type' => 'required|in_list[percentage,percent,fixed]',
            'discount_value' => 'required|decimal',
            'max_uses' => 'permit_empty|integer',
            'min_value' => 'permit_empty|decimal',
            'valid_from' => 'permit_empty|valid_date',
            'valid_until' => 'permit_empty|valid_date',
            'is_active' => 'permit_empty'
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'code' => strtoupper($this->request->getPost('code')),
            'discount_type' => $this->request->getPost('discount_type'),
            'discount_value' => $this->request->getPost('discount_value'),
            'max_uses' => $this->request->getPost('max_uses') ?: null,
            'min_value' => $this->request->getPost('min_value') ?: null,
            'valid_from' => $this->request->getPost('valid_from') ?: null,
            'valid_until' => $this->request->getPost('valid_until') ?: null,
            'is_active' => $this->request->getPost('is_active') ? true : false,
            'used_count' => 0
        ];

        if ($this->couponModel->insert($data)) {
            return redirect()->to('admin/coupons')->with('success', 'Cupom criado com sucesso!');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao criar cupom.');
    }

    /**
     * Formulário de edição
     */
    public function edit($id)
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $coupon = $this->couponModel->find($id);

        if (!$coupon) {
            return redirect()->to('admin/coupons')->with('error', 'Cupom não encontrado.');
        }

        return view('admin/coupons/form', [
            'coupon' => $coupon,
            'action' => 'edit'
        ]);
    }

    /**
     * Atualizar cupom
     */
    public function update($id)
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $coupon = $this->couponModel->find($id);

        if (!$coupon) {
            return redirect()->to('admin/coupons')->with('error', 'Cupom não encontrado.');
        }

        $rules = [
            'code' => "required|min_length[3]|max_length[50]|is_unique[coupons.code,id,{$id}]",
            'discount_type' => 'required|in_list[percentage,percent,fixed]',
            'discount_value' => 'required|decimal',
            'max_uses' => 'permit_empty|integer',
            'min_value' => 'permit_empty|decimal',
            'valid_from' => 'permit_empty|valid_date',
            'valid_until' => 'permit_empty|valid_date',
            'is_active' => 'permit_empty'
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'code' => strtoupper($this->request->getPost('code')),
            'discount_type' => $this->request->getPost('discount_type'),
            'discount_value' => $this->request->getPost('discount_value'),
            'max_uses' => $this->request->getPost('max_uses') ?: null,
            'min_value' => $this->request->getPost('min_value') ?: null,
            'valid_from' => $this->request->getPost('valid_from') ?: null,
            'valid_until' => $this->request->getPost('valid_until') ?: null,
            'is_active' => $this->request->getPost('is_active') ? true : false
        ];

        if ($this->couponModel->update($id, $data)) {
            return redirect()->to('admin/coupons')->with('success', 'Cupom atualizado com sucesso!');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao atualizar cupom.');
    }

    /**
     * Deletar cupom
     */
    public function delete($id)
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return $this->response->setJSON(['error' => 'Acesso negado'])->setStatusCode(403);
        }

        $coupon = $this->couponModel->find($id);

        if (!$coupon) {
            return $this->response->setJSON(['error' => 'Cupom não encontrado'])->setStatusCode(404);
        }

        if ($this->couponModel->delete($id)) {
            return $this->response->setJSON(['success' => true]);
        }

        return $this->response->setJSON(['error' => 'Erro ao deletar cupom'])->setStatusCode(500);
    }

    /**
     * Ativar/Desativar cupom
     */
    public function toggle($id)
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return $this->response->setJSON(['error' => 'Acesso negado'])->setStatusCode(403);
        }

        $coupon = $this->couponModel->find($id);

        if (!$coupon) {
            return $this->response->setJSON(['error' => 'Cupom não encontrado'])->setStatusCode(404);
        }

        $newStatus = !$coupon->is_active;
        
        if ($this->couponModel->update($id, ['is_active' => $newStatus])) {
            return $this->response->setJSON([
                'success' => true,
                'active' => $newStatus
            ]);
        }

        return $this->response->setJSON(['error' => 'Erro ao atualizar status'])->setStatusCode(500);
    }

    /**
     * Relatório de uso de cupons
     */
    public function report()
    {
        if (!auth()->user()->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        // Top cupons mais usados
        $topCoupons = $this->couponModel
            ->orderBy('current_uses', 'DESC')
            ->limit(10)
            ->findAll();

        // Cupons expirados
        $expiredCoupons = $this->couponModel
            ->where('valid_until <', date('Y-m-d'))
            ->where('active', true)
            ->countAllResults();

        // Total de descontos concedidos (estimativa)
        $totalDiscount = $this->couponModel
            ->selectSum('value * current_uses', 'total_discount')
            ->where('type', 'fixed')
            ->get()
            ->getRow()
            ->total_discount ?? 0;

        return view('admin/coupons/report', [
            'topCoupons' => $topCoupons,
            'expiredCoupons' => $expiredCoupons,
            'totalDiscount' => $totalDiscount
        ]);
    }
}
