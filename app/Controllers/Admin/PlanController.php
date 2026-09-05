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

        return view('Admin/plans/index', ['plans' => $plans]);
    }

    public function new()
    {
        return view('Admin/plans/form', ['plan' => null]);
    }

    public function create()
    {
        $data = $this->request->getPost();
        
        // Generate 'chave' (slug) if missing
        if (empty($data['chave']) && !empty($data['nome'])) {
            $data['chave'] = url_title($data['nome'], '-', true);
        }
        // Campo vazio significa "ilimitado" (NULL), não string vazia — que o
        // Postgres rejeitaria numa coluna INT.
        foreach (['limite_imoveis_ativos', 'limite_turbo_mensal'] as $limite) {
            $data[$limite] = ($data[$limite] ?? '') === '' ? null : $data[$limite];
        }
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

        return redirect()->back()->withInput()->with('errors', $this->planModel->errors())->with('error', 'Erro ao criar plano. Verifique os dados fornecidos.');
    }

    public function edit($id)
    {
        $plan = $this->planModel->find($id);
        if (!$plan) {
            return redirect()->to('admin/plans')->with('error', 'Plano não encontrado.');
        }
        return view('Admin/plans/form', ['plan' => $plan]);
    }

    public function update($id)
    {
        $data = $this->request->getPost();
        $data['id'] = $id;
        
        // Campo vazio significa "ilimitado" (NULL), não string vazia — que o
        // Postgres rejeitaria numa coluna INT.
        foreach (['limite_imoveis_ativos', 'limite_turbo_mensal'] as $limite) {
            $data[$limite] = ($data[$limite] ?? '') === '' ? null : $data[$limite];
        }
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
        
        return redirect()->back()->withInput()->with('errors', $this->planModel->errors())->with('error', 'Erro ao atualizar plano. Verifique os dados fornecidos.');
    }

    public function delete($id)
    {
        // Guard herdado do PlansController removido: remover um plano em uso
        // deixaria assinaturas apontando para um plano soft-deleted, e todo
        // caminho que resolve limite/preço via `plans` passaria a falhar para
        // aquelas contas. Desative o plano em vez de removê-lo.
        $activeSubscriptions = model('App\Models\SubscriptionModel')
            ->where('plan_id', $id)
            ->whereIn('status', ['ACTIVE', 'TRIAL', 'PENDING', 'AWAITING_PAYMENT'])
            ->countAllResults();

        if ($activeSubscriptions > 0) {
            return redirect()->to('admin/plans')->with(
                'error',
                "Não é possível remover: {$activeSubscriptions} assinatura(s) usam este plano. Desative-o em vez de removê-lo."
            );
        }

        if ($this->planModel->delete($id)) {
            return redirect()->to('admin/plans')->with('message', 'Plano removido.');
        }

        return redirect()->to('admin/plans')->with('error', 'Erro ao remover plano.');
    }
}
