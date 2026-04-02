<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('plans');

        // Limpar todos os planos antigos
        $builder->truncate();

        $plans = [
            [
                'chave'                   => 'PRATA',
                'nome'                    => 'Plano Prata',
                'limite_imoveis_ativos'   => 45,
                'limite_turbo_mensal'     => 10,
                'limite_api_requests_dia' => 1000,
                'preco_mensal'            => 1850.00,
                'preco_anual'             => 1599.90,
            ],
            [
                'chave'                   => 'OURO',
                'nome'                    => 'Plano Ouro',
                'limite_imoveis_ativos'   => 89,
                'limite_turbo_mensal'     => 15,
                'limite_api_requests_dia' => 5000,
                'preco_mensal'            => 2850.00,
                'preco_anual'             => 2599.90,
            ],
            [
                'chave'                   => 'DIAMANTE',
                'nome'                    => 'Plano Diamante',
                'limite_imoveis_ativos'   => null, // Ilimitado
                'limite_turbo_mensal'     => null, // Ilimitado
                'limite_api_requests_dia' => 50000,
                'preco_mensal'            => 4250.00,
                'preco_anual'             => 3999.90,
            ],
        ];

        foreach ($plans as $plan) {
            $builder->insert($plan);
        }
    }
}
