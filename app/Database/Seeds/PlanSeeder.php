<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Models\PlanModel;

class PlanSeeder extends Seeder
{
    public function run()
    {
        $planModel = new PlanModel();

        $plans = [
            [
                'chave'                   => 'START',
                'nome'                    => 'Plano Start',
                'limite_imoveis_ativos'   => 20,
                'limite_turbo_mensal'     => 0,
                'limite_api_requests_dia' => 100,
                'preco_mensal'            => 49.90, // Exemplo
            ],
            [
                'chave'                   => 'PRO',
                'nome'                    => 'Plano Pro',
                'limite_imoveis_ativos'   => 80,
                'limite_turbo_mensal'     => 5,
                'limite_api_requests_dia' => 1000,
                'preco_mensal'            => 149.90, // Exemplo
            ],
            [
                'chave'                   => 'IMOBILIARIA',
                'nome'                    => 'Plano Imobiliária',
                'limite_imoveis_ativos'   => null, // Ilimitado
                'limite_turbo_mensal'     => 20,
                'limite_api_requests_dia' => 5000,
                'preco_mensal'            => 399.90, // Exemplo
            ],
        ];

        foreach ($plans as $plan) {
            $existing = $planModel->where('chave', $plan['chave'])->first();
            if (!$existing) {
                $planModel->save($plan);
            }
        }
    }
}
