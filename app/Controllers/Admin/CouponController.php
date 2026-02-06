<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CouponModel;

class CouponController extends BaseController
{
    protected $couponModel;

    public function __construct()
    {
        $this->couponModel = new CouponModel();
    }

    public function index()
    {
        $data = [
            'coupons' => $this->couponModel->orderBy('created_at', 'DESC')->findAll()
        ];
        return view('admin/coupons/index', $data);
    }

    public function create()
    {
        return view('admin/coupons/form', ['coupon' => null]);
    }

    public function store()
    {
        $rules = [
            'code' => 'required|min_length[3]|alpha_numeric|is_unique[coupons.code]',
            'discount_value' => 'required|numeric|greater_than[0]',
            'discount_type' => 'required|in_list[percent,fixed]'
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        
        // Formatar datas vazias para null
        $data['valid_from'] = !empty($data['valid_from']) ? $data['valid_from'] : null;
        $data['valid_until'] = !empty($data['valid_until']) ? $data['valid_until'] : null;
        $data['max_uses'] = !empty($data['max_uses']) ? $data['max_uses'] : null;
        $data['is_active'] = isset($data['is_active']) ? 1 : 0;
        $data['code'] = strtoupper($data['code']); // Force uppercase

        if ($this->couponModel->save($data)) {
            return redirect()->to('admin/coupons')->with('message', 'Cupom criado com sucesso!');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao salvar cupom.');
    }

    public function edit($id)
    {
        $coupon = $this->couponModel->find($id);
        if (!$coupon) {
            return redirect()->to('admin/coupons')->with('error', 'Cupom não encontrado.');
        }
        return view('admin/coupons/form', ['coupon' => $coupon]);
    }

    public function update($id)
    {
        $coupon = $this->couponModel->find($id);
        if (!$coupon) {
            return redirect()->to('admin/coupons')->with('error', 'Cupom não encontrado.');
        }

        $rules = [
            'code' => "required|min_length[3]|alpha_numeric|is_unique[coupons.code,id,{$id}]",
            'discount_value' => 'required|numeric|greater_than[0]',
            'discount_type' => 'required|in_list[percent,fixed]'
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        
        $data['id'] = $id;
        $data['valid_from'] = !empty($data['valid_from']) ? $data['valid_from'] : null;
        $data['valid_until'] = !empty($data['valid_until']) ? $data['valid_until'] : null;
        $data['max_uses'] = !empty($data['max_uses']) ? $data['max_uses'] : null;
        $data['is_active'] = isset($data['is_active']) ? 1 : 0;
        $data['code'] = strtoupper($data['code']);

        if ($this->couponModel->save($data)) {
            return redirect()->to('admin/coupons')->with('message', 'Cupom atualizado com sucesso!');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao atualizar cupom.');
    }
    
    public function delete($id)
    {
        if ($this->couponModel->delete($id)) {
            return redirect()->to('admin/coupons')->with('message', 'Cupom excluído com sucesso!');
        }
        return redirect()->to('admin/coupons')->with('error', 'Erro ao excluir cupom.');
    }
}
