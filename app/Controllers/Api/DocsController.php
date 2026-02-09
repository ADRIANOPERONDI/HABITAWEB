<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class DocsController extends BaseController
{
    public function index()
    {
        return view('api/swagger');
    }

    public function json()
    {
        $path = FCPATH . 'openapi.json';
        if (file_exists($path)) {
            $json = file_get_contents($path);
            return $this->response->setJSON(json_decode($json, true));
        }

        return $this->response->setJSON(['error' => 'OpenAPI file not found']);
    }

    public function testSuite()
    {
        // 1. Rodar Migrações (Desta vez via código, mas sem chamadas recursivas)
        $migrations = \Config\Services::migrations();
        $results = [];
        try {
            if ($migrations->latest()) {
                $results['migrations'] = 'Migrações executadas ou já atualizadas.';
            } else {
                $results['migrations'] = 'Nenhuma migração nova para rodar.';
            }
        } catch (\Exception $e) {
            $results['migrations'] = 'Erro: ' . $e->getMessage();
        }

        // 2. Verificar Tabelas Críticas
        $db = \Config\Database::connect();
        $results['table_check'] = [
            'api_keys' => $db->tableExists('api_keys') ? 'OK' : 'FALTA',
            'system_settings' => $db->tableExists('system_settings') ? 'OK' : 'FALTA',
        ];

        // 3. Gerar Chave de Teste para uso externo
        $apiKeyModel = model('App\Models\ApiKeyModel');
        $account = model('App\Models\AccountModel')->first();
        $user = model('CodeIgniter\Shield\Models\UserModel')->first();
        
        if ($account && $user) {
            $keyResult = $apiKeyModel->generateKey($account->id, 'Chave de Teste Externo', $user->id);
            $results['test_key'] = $keyResult['plain_key'];
        }

        return $this->response->setJSON([
            'status' => 'success',
            'results' => $results
        ]);
    }
}
