<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\PropertyAlertModel;

class PropertyAlertController extends BaseController
{
    public function create()
    {
        $rules = [
            'email'      => 'required|valid_email',
            'filtros'    => 'required',
            'frequencia' => 'required|in_list[IMEDIATO,DIARIO,SEMANAL]',
        ];

        if (!$this->validate($rules)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors'  => $this->validator->getErrors()
            ])->setStatusCode(400);
        }

        $model = new PropertyAlertModel();
        
        $data = [
            'email'      => $this->request->getPost('email'),
            'frequencia' => $this->request->getPost('frequencia'),
            'filtros'    => $this->request->getPost('filtros'),
            'status'     => 'ATIVO',
        ];

        // Se o usuário já tiver alerta IGUAL, apenas atualiza a frequência
        $existing = $model->where('email', $data['email'])
                          ->where('filtros', $data['filtros'])
                          ->first();

        if ($existing) {
             $model->update($existing['id'], ['frequencia' => $data['frequencia'], 'status' => 'ATIVO']);
             return $this->response->setJSON([
                'success' => true,
                'message' => 'Alerta já existia e foi reativado/atualizada a frequência.'
            ]);
        }

        if ($model->insert($data)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Alerta criado com sucesso! Você receberá novidades em breve.'
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => 'Erro ao salvar no banco de dados.'
        ])->setStatusCode(500);
    }
}
