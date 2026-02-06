<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Config\Factories;

class PlanController extends BaseController
{
    protected $planModel;

    public function __construct()
    {
        $this->planModel = Factories::models(\App\Models\PlanModel::class);
    }

    public function index()
    {
        // Apenas admin pode acessar (Middleware já protege rota 'admin', mas reforçar se necessário)
        // Por simplificação, assumimos grupo admin na rota.

        $plans = $this->planModel->orderBy('preco_mensal', 'ASC')->findAll();

        return view('admin/plans/index', ['plans' => $plans]);
    }

    public function new()
    {
        return view('admin/plans/form', ['plan' => null]);
    }

    public function create()
    {
        $data = $this->request->getPost();
        
        // Generate 'chave' (slug) if missing
        if (empty($data['chave']) && !empty($data['nome'])) {
            $data['chave'] = url_title($data['nome'], '-', true);
        }
        $data['limite_imoveis_ativos'] = empty($data['limite_imoveis_ativos']) ? null : $data['limite_imoveis_ativos'];
        $data['ativo'] = isset($data['ativo']) ? 't' : 'f';

        // Sanitize Currency
        $currencyFields = ['preco_mensal', 'preco_trimestral', 'preco_semestral', 'preco_anual'];
        foreach ($currencyFields as $field) {
            if (!empty($data[$field])) {
                $data[$field] = str_replace(['.', ','], ['', '.'], $data[$field]);
            }
        }

        if ($this->planModel->save($data)) {
            return redirect()->to('admin/plans')->with('message', 'Plano criado com sucesso.');
        }

        return redirect()->back()->withInput()->with('error', 'Erro ao criar plano.');
    }

    public function edit($id)
    {
        $plan = $this->planModel->find($id);
        if (!$plan) {
            return redirect()->to('admin/plans')->with('error', 'Plano não encontrado.');
        }
        return view('admin/plans/form', ['plan' => $plan]);
    }

    public function update($id)
    {
        $data = $this->request->getPost();
        $data['id'] = $id;
        
        $data['limite_imoveis_ativos'] = empty($data['limite_imoveis_ativos']) ? null : $data['limite_imoveis_ativos'];
        $data['ativo'] = isset($data['ativo']) ? 't' : 'f';

        // Sanitize Currency
        $currencyFields = ['preco_mensal', 'preco_trimestral', 'preco_semestral', 'preco_anual'];
        foreach ($currencyFields as $field) {
            if (!empty($data[$field])) {
                $data[$field] = str_replace(['.', ','], ['', '.'], $data[$field]);
            }
        }

        if ($this->planModel->save($data)) {
            return redirect()->to('admin/plans')->with('message', 'Plano atualizado com sucesso.');
        }
        
        return redirect()->back()->withInput()->with('error', 'Erro ao atualizar plano.');
    }

    public function delete($id)
    {
        // Soft delete ou check se há assinaturas ativas antes
        // Por simplificação, delete direto (model deve ter soft delete se configurado)
        if ($this->planModel->delete($id)) {
            return redirect()->to('admin/plans')->with('message', 'Plano removido.');
        }
        return redirect()->to('admin/plans')->with('error', 'Erro ao remover plano.');
    }
}
