<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class PlansController extends BaseController
{
    protected $planModel;

    public function __construct()
    {
        $this->planModel = model('App\Models\PlanModel');
    }

    /**
     * Listagem de planos (Super Admin)
     */
    public function index()
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $plans = $this->planModel->orderBy('preco_mensal', 'ASC')->findAll();

        return view('admin/plans/index', [
            'plans' => $plans
        ]);
    }

    /**
     * Formulário de criação
     */
    public function create()
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        return view('admin/plans/form', [
            'plan' => null,
            'action' => 'create'
        ]);
    }

    /**
     * Salvar novo plano
     */
    public function store()
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $rules = [
            'nome' => 'required|min_length[3]|max_length[100]',
            'descricao' => 'permit_empty|max_length[500]',
            'preco_mensal' => 'required|decimal',
            'limite_imoveis' => 'required|integer',
            'destacar_imoveis' => 'required|integer',
            'fotos_por_imovel' => 'required|integer',
            'ativo' => 'permit_empty'
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'nome' => $this->request->getPost('nome'),
            'descricao' => $this->request->getPost('descricao'),
            'preco_mensal' => $this->request->getPost('preco_mensal'),
            'limite_imoveis' => $this->request->getPost('limite_imoveis'),
            'destacar_imoveis' => $this->request->getPost('destacar_imoveis'),
            'fotos_por_imovel' => $this->request->getPost('fotos_por_imovel'),
            'ativo' => $this->request->getPost('ativo') ? true : false
        ];

        if ($this->planModel->insert($data)) {
            return redirect()->to('admin/plans')->with('success', 'Plano criado com sucesso!');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao criar plano.');
    }

    /**
     * Formulário de edição
     */
    public function edit($id)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $plan = $this->planModel->find($id);

        if (!$plan) {
            return redirect()->to('admin/plans')->with('error', 'Plano não encontrado.');
        }

        return view('admin/plans/form', [
            'plan' => $plan,
            'action' => 'edit'
        ]);
    }

    /**
     * Atualizar plano
     */
    public function update($id)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $plan = $this->planModel->find($id);

        if (!$plan) {
            return redirect()->to('admin/plans')->with('error', 'Plano não encontrado.');
        }

        $rules = [
            'nome' => 'required|min_length[3]|max_length[100]',
            'descricao' => 'permit_empty|max_length[500]',
            'preco_mensal' => 'required|decimal',
            'limite_imoveis' => 'required|integer',
            'destacar_imoveis' => 'required|integer',
            'fotos_por_imovel' => 'required|integer',
            'ativo' => 'permit_empty'
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'nome' => $this->request->getPost('nome'),
            'descricao' => $this->request->getPost('descricao'),
            'preco_mensal' => $this->request->getPost('preco_mensal'),
            'limite_imoveis' => $this->request->getPost('limite_imoveis'),
            'destacar_imoveis' => $this->request->getPost('destacar_imoveis'),
            'fotos_por_imovel' => $this->request->getPost('fotos_por_imovel'),
            'ativo' => $this->request->getPost('ativo') ? true : false
        ];

        if ($this->planModel->update($id, $data)) {
            return redirect()->to('admin/plans')->with('success', 'Plano atualizado com sucesso!');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao atualizar plano.');
    }

    /**
     * Ativar/Desativar plano
     */
    public function toggle($id)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return $this->response->setJSON(['error' => 'Acesso negado'])->setStatusCode(403);
        }

        $plan = $this->planModel->find($id);

        if (!$plan) {
            return $this->response->setJSON(['error' => 'Plano não encontrado'])->setStatusCode(404);
        }

        $newStatus = !$plan->ativo;
        
        if ($this->planModel->update($id, ['ativo' => $newStatus])) {
            return $this->response->setJSON([
                'success' => true,
                'ativo' => $newStatus
            ]);
        }

        return $this->response->setJSON(['error' => 'Erro ao atualizar status'])->setStatusCode(500);
    }

    /**
     * Deletar plano
     */
    public function delete($id)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $plan = $this->planModel->find($id);

        if (!$plan) {
            return redirect()->to('admin/plans')->with('error', 'Plano não encontrado.');
        }

        // Verificar se há subscriptions ativas neste plano
        $subscriptionModel = model('App\Models\SubscriptionModel');
        $activeSubscriptions = $subscriptionModel->where('plan_id', $id)
                                                 ->whereIn('status', ['ATIVA', 'ACTIVE'])
                                                 ->countAllResults();

        if ($activeSubscriptions > 0) {
            return redirect()->back()->with('error', 'Não é possível deletar um plano com assinaturas ativas.');
        }

        if ($this->planModel->delete($id)) {
            return redirect()->to('admin/plans')->with('success', 'Plano deletado com sucesso!');
        }

        return redirect()->back()->with('error', 'Erro ao deletar plano.');
    }
}
