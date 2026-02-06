<?php

namespace App\Services;

use App\Models\ClientModel;
use CodeIgniter\Config\Factories;

class ClientService
{
    protected ClientModel $clientModel;

    public function __construct()
    {
        $this->clientModel = Factories::models(ClientModel::class);
    }

    public function listClients(?int $accountId, array $filters = []): array
    {
        $builder = $this->clientModel;

        if ($accountId) {
            $builder->where('account_id', $accountId);
        }

        if (!empty($filters['term'])) {
            $builder->groupStart()
                ->like('nome', $filters['term'])
                ->orLike('email', $filters['term'])
                ->orLike('cpf_cnpj', $filters['term'])
            ->groupEnd();
        }

        return $builder->orderBy('nome', 'ASC')->findAll();
    }

    public function saveClient(array $data, ?int $id = null): array
    {
        if ($id) {
            $data['id'] = $id;
        }

        if ($this->clientModel->save($data)) {
            return [
                'success' => true,
                'message' => 'Cliente salvo com sucesso.',
                'id'      => $id ?? $this->clientModel->getInsertID()
            ];
        }

        return [
            'success' => false,
            'message' => 'Erro ao salvar cliente.',
            'errors'  => $this->clientModel->errors()
        ];
    }

    public function getClient(int $id, ?int $accountId)
    {
        $builder = $this->clientModel->where('id', $id);
        
        if ($accountId) {
            $builder->where('account_id', $accountId);
        }

        return $builder->first();
    }
}
