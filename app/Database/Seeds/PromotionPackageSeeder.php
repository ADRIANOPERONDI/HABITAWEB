<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PromotionPackageSeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();
        $builder = $db->table('promotion_packages');

        // Limpar pacotes antigos usando query direta
        $db->query('DELETE FROM promotion_packages');

        $data = [
            // Pacotes de Turbinar Imóvel
            [
                'chave'         => 'TURBO_7_DIAS',
                'nome'          => 'Turbinar Imóvel - 7 dias',
                'tipo_promocao' => 'TURBO_IMOVEL',
                'duracao_dias'  => 7,
                'preco'         => 50.00,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
            // Pacotes de Lead - Compra
            [
                'chave'         => 'LEAD_COMPRA',
                'nome'          => 'Lead - Compra',
                'tipo_promocao' => 'LEAD',
                'duracao_dias'  => 0, // Não tem duração, é por unidade
                'preco'         => 80.00,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
            // Pacotes de Lead - Aluguel
            [
                'chave'         => 'LEAD_ALUGUEL',
                'nome'          => 'Lead - Aluguel',
                'tipo_promocao' => 'LEAD',
                'duracao_dias'  => 0, // Não tem duração, é por unidade
                'preco'         => 40.00,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
        ];

        foreach ($data as $row) {
            $builder->insert($row);
        }
    }
}
