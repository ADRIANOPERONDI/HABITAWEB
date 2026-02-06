<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PromotionPackageSeeder extends Seeder
{
    public function run()
    {
        $data = [
            [
                'chave'         => 'DESTAQUE_SEMANAL',
                'nome'          => 'Turbo Prata (7 dias)',
                'tipo_promocao' => 'DESTAQUE',
                'duracao_dias'  => 7,
                'preco'         => 29.90,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
            [
                'chave'         => 'SUPER_MENSAL',
                'nome'          => 'Turbo Ouro (30 dias)',
                'tipo_promocao' => 'SUPER_DESTAQUE',
                'duracao_dias'  => 30,
                'preco'         => 79.90,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
            [
                'chave'         => 'VITRINE_GLOBAL',
                'nome'          => 'Turbo Diamante (30 dias)',
                'tipo_promocao' => 'VITRINE',
                'duracao_dias'  => 30,
                'preco'         => 149.90,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
        ];

        // Using simple query to avoid model issues if table empty
        $db = \Config\Database::connect();
        $builder = $db->table('promotion_packages');

        foreach ($data as $row) {
            // Upsert based on chave
            if ($builder->where('chave', $row['chave'])->countAllResults() == 0) {
                $builder->insert($row);
            }
        }
    }
}
