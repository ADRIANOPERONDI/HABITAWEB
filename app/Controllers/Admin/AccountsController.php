<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class AccountsController extends BaseController
{
    public function index()
    {
        $model = model('App\Models\AccountModel');
        
        $data = [
            'accounts' => $model->orderBy('id', 'DESC')->paginate(20),
            'pager' => $model->pager
        ];

        return view('Admin/accounts/index', $data);
    }

    public function edit($id)
    {
        $model = model('App\Models\AccountModel');
        $account = $model->find($id);

        if (!$account) {
            return redirect()->to('admin/accounts')->with('error', 'Conta não encontrada.');
        }

        return view('Admin/accounts/form', [
            'account' => $account
        ]);
    }

    public function update($id)
    {
        $model = model('App\Models\AccountModel');
        $account = $model->find($id);

        if (!$account) {
            return redirect()->to('admin/accounts')->with('error', 'Conta não encontrada.');
        }

        $data = $this->request->getPost();

        // Checkbox desmarcado não é enviado no POST — sem isto, uma vez
        // marcada, a isenção nunca seria desligada pela tela.
        $data['cobranca_leads_isenta'] = ! empty($data['cobranca_leads_isenta']);

        if ($model->update($id, $data)) {
            return redirect()->to('admin/accounts')->with('message', 'Conta atualizada com sucesso.');
        }

        return redirect()->back()->withInput()->with('errors', $model->errors());
    }
}
