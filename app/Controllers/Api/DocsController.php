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
        $data = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Habitaweb API',
                'description' => 'Documentação da API do Habitaweb.',
                'version' => '1.0.0'
            ],
            'servers' => [
                ['url' => site_url('api/v1')]
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT' // Ou SHIELD TOKEN
                    ]
                ]
            ],
            'security' => [
                ['bearerAuth' => []]
            ],
            'paths' => [
                '/properties' => [
                    'get' => [
                        'summary' => 'Listar Imóveis',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'cidade', 'in' => 'query', 'schema' => ['type' => 'string']]
                        ],
                        'responses' => [
                            '200' => ['description' => 'Lista de imóveis retornada com sucesso']
                        ]
                    ]
                ],
                '/leads' => [
                    'post' => [
                        'summary' => 'Criar Lead',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'property_id' => ['type' => 'integer'],
                                            'nome_visitante' => ['type' => 'string'],
                                            'email_visitante' => ['type' => 'string']
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'responses' => [
                            '201' => ['description' => 'Lead criado']
                        ]
                    ]
                ]
            ]
        ];
        return $this->response->setJSON($data);
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
