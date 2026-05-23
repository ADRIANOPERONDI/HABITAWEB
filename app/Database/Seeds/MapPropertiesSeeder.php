<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class MapPropertiesSeeder extends Seeder
{
    public function run()
    {
        // Buscar a primeira conta ativa no banco de dados para vincular os imóveis
        $account = $this->db->table('accounts')
                            ->where('status', 'ACTIVE')
                            ->orderBy('id', 'ASC')
                            ->get()
                            ->getRow();

        if (!$account) {
            echo "Nenhuma conta ativa encontrada na tabela accounts. Rode o MainSeeder primeiro.\n";
            return;
        }

        $accountId = $account->id;
        echo "Gerando imóveis geográficos premium para a Conta ID: $accountId ($account->nome)...\n";

        $properties = [
            // ================= SÃO MIGUEL DO OESTE =================
            [
                'titulo' => 'Apartamento Central Premium',
                'cidade' => 'São Miguel do Oeste',
                'bairro' => 'Centro',
                'latitude' => -26.7323,
                'longitude' => -53.5186,
                'preco' => 350000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 2,
                'banheiros' => 1,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 75,
                'score_qualidade' => 95,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Casa no Bairro Agostini',
                'cidade' => 'São Miguel do Oeste',
                'bairro' => 'Agostini',
                'latitude' => -26.7200,
                'longitude' => -53.5300,
                'preco' => 500000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'CASA',
                'quartos' => 3,
                'banheiros' => 2,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 150,
                'score_qualidade' => 88,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Kitnet Estudantil Mobiliada',
                'cidade' => 'São Miguel do Oeste',
                'bairro' => 'São Jorge',
                'latitude' => -26.7250,
                'longitude' => -53.5100,
                'preco' => 950,
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 1,
                'banheiros' => 1,
                'vagas' => 0,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 35,
                'score_qualidade' => 85,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Sobrado Familiar na Estrela',
                'cidade' => 'São Miguel do Oeste',
                'bairro' => 'Estrela',
                'latitude' => -26.7400,
                'longitude' => -53.5250,
                'preco' => 680000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'CASA',
                'quartos' => 4,
                'banheiros' => 3,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 210,
                'score_qualidade' => 92,
                'publicado_em' => date('Y-m-d H:i:s')
            ],

            // ================= PORTO ALEGRE =================
            [
                'titulo' => 'Apartamento Frente Parque Moinhos',
                'cidade' => 'Porto Alegre',
                'bairro' => 'Moinhos de Vento',
                'latitude' => -30.0260,
                'longitude' => -51.2050,
                'preco' => 1250000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 3,
                'banheiros' => 3,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 130,
                'score_qualidade' => 98,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Lindo Loft no Menino Deus',
                'cidade' => 'Porto Alegre',
                'bairro' => 'Menino Deus',
                'latitude' => -30.0480,
                'longitude' => -51.2220,
                'preco' => 2800,
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 1,
                'banheiros' => 1,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 48,
                'score_qualidade' => 90,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Cobertura Duplex em Petrópolis',
                'cidade' => 'Porto Alegre',
                'bairro' => 'Petrópolis',
                'latitude' => -30.0380,
                'longitude' => -51.1850,
                'preco' => 950000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 3,
                'banheiros' => 3,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 180,
                'score_qualidade' => 94,
                'publicado_em' => date('Y-m-d H:i:s')
            ],

            // ================= CURITIBA =================
            [
                'titulo' => 'Apartamento Integrado Batel',
                'cidade' => 'Curitiba',
                'bairro' => 'Batel',
                'latitude' => -25.4430,
                'longitude' => -49.2820,
                'preco' => 890000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 2,
                'banheiros' => 2,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 88,
                'score_qualidade' => 96,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Studio Moderno Água Verde',
                'cidade' => 'Curitiba',
                'bairro' => 'Água Verde',
                'latitude' => -25.4540,
                'longitude' => -49.2780,
                'preco' => 2200,
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 1,
                'banheiros' => 1,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 40,
                'score_qualidade' => 89,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Sobrado no Cabral com Quintal',
                'cidade' => 'Curitiba',
                'bairro' => 'Cabral',
                'latitude' => -25.4090,
                'longitude' => -49.2520,
                'preco' => 790000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'CASA',
                'quartos' => 3,
                'banheiros' => 3,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 165,
                'score_qualidade' => 91,
                'publicado_em' => date('Y-m-d H:i:s')
            ],

            // ================= BELO HORIZONTE =================
            [
                'titulo' => 'Apartamento com Varanda Gourmet na Savassi',
                'cidade' => 'Belo Horizonte',
                'bairro' => 'Savassi',
                'latitude' => -19.9380,
                'longitude' => -43.9350,
                'preco' => 1100000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 3,
                'banheiros' => 2,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 105,
                'score_qualidade' => 97,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Cobertura Vista Panorâmica Belvedere',
                'cidade' => 'Belo Horizonte',
                'bairro' => 'Belvedere',
                'latitude' => -19.9780,
                'longitude' => -43.9420,
                'preco' => 2400000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 4,
                'banheiros' => 4,
                'vagas' => 3,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 250,
                'score_qualidade' => 95,
                'publicado_em' => date('Y-m-d H:i:s')
            ],

            // ================= SÃO PAULO =================
            [
                'titulo' => 'Apartamento Luxo Bela Vista',
                'cidade' => 'São Paulo',
                'bairro' => 'Bela Vista',
                'latitude' => -23.5615,
                'longitude' => -46.6560,
                'preco' => 1200000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 2,
                'banheiros' => 2,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 90,
                'score_qualidade' => 94,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Studio Moderno Pinheiros',
                'cidade' => 'São Paulo',
                'bairro' => 'Pinheiros',
                'latitude' => -23.5670,
                'longitude' => -46.6950,
                'preco' => 3500,
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 1,
                'banheiros' => 1,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 42,
                'score_qualidade' => 91,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Casa de Vila no Tatuapé',
                'cidade' => 'São Paulo',
                'bairro' => 'Tatuapé',
                'latitude' => -23.5400,
                'longitude' => -46.5750,
                'preco' => 850000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'CASA',
                'quartos' => 3,
                'banheiros' => 2,
                'vagas' => 2,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 140,
                'score_qualidade' => 88,
                'publicado_em' => date('Y-m-d H:i:s')
            ],

            // ================= RIO DE JANEIRO =================
            [
                'titulo' => 'Apartamento Quadra da Praia Copacabana',
                'cidade' => 'Rio de Janeiro',
                'bairro' => 'Copacabana',
                'latitude' => -22.9690,
                'longitude' => -43.1850,
                'preco' => 790000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 2,
                'banheiros' => 2,
                'vagas' => 0,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 80,
                'score_qualidade' => 93,
                'publicado_em' => date('Y-m-d H:i:s')
            ],
            [
                'titulo' => 'Apartamento com Varanda Ipanema',
                'cidade' => 'Rio de Janeiro',
                'bairro' => 'Ipanema',
                'latitude' => -22.9830,
                'longitude' => -43.2010,
                'preco' => 1650000,
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'quartos' => 2,
                'banheiros' => 2,
                'vagas' => 1,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 95,
                'score_qualidade' => 96,
                'publicado_em' => date('Y-m-d H:i:s')
            ]
        ];

        $properties = array_merge($properties, $this->buildSaoPauloPortfolio($accountId));

        foreach ($properties as $p) {
            $p['created_at'] = date('Y-m-d H:i:s');
            $p['updated_at'] = date('Y-m-d H:i:s');
            
            if (!$this->db->table('properties')->insert($p)) {
                echo "x";
                continue;
            }
            $propId = $this->db->insertID();

            // Adicionar fotos realistas
            for ($j = 0; $j < 3; $j++) {
                $imgUrl = "https://picsum.photos/seed/prop_map_{$propId}_{$j}/800/600";
                
                $mediaItem = [
                    'property_id' => $propId,
                    'url'         => $imgUrl,
                    'tipo'        => 'FOTO',
                    'principal'   => ($j === 0) ? 't' : 'f',
                    'ordem'       => $j,
                    'created_at'  => date('Y-m-d H:i:s')
                ];
                $this->db->table('property_media')->insert($mediaItem);
            }
            echo ".";
        }
        echo "\nSeeder MapPropertiesSeeder rodado com sucesso!\n";
    }

    private function buildSaoPauloPortfolio(int $accountId): array
    {
        $now = date('Y-m-d H:i:s');
        $spots = [
            ['Jardins', -23.5686, -46.6684],
            ['Itaim Bibi', -23.5842, -46.6784],
            ['Moema', -23.6022, -46.6636],
            ['Vila Mariana', -23.5894, -46.6346],
            ['Perdizes', -23.5357, -46.6807],
            ['Higienópolis', -23.5448, -46.6576],
            ['Brooklin', -23.6221, -46.6942],
            ['Santana', -23.5015, -46.6253],
            ['Tatuapé', -23.5400, -46.5750],
            ['Pinheiros', -23.5670, -46.6950],
            ['Vila Olímpia', -23.5953, -46.6852],
            ['Aclimação', -23.5714, -46.6291],
            ['Campo Belo', -23.6268, -46.6692],
            ['Bela Vista', -23.5615, -46.6560],
            ['Lapa', -23.5275, -46.7032],
            ['Morumbi', -23.6090, -46.7202],
        ];

        $portfolio = [];
        foreach ($spots as $index => [$bairro, $lat, $lng]) {
            $isRent = in_array($bairro, ['Itaim Bibi', 'Moema', 'Pinheiros', 'Vila Olímpia', 'Brooklin'], true);
            $tipo = $index % 4 === 0 ? 'CASA' : ($index % 5 === 0 ? 'SALA_COMERCIAL' : 'APARTAMENTO');
            $preco = $isRent
                ? [3200, 4800, 6500, 8200, 10500][$index % 5]
                : [520000, 690000, 850000, 1180000, 1450000, 2200000][$index % 6];

            $portfolio[] = [
                'titulo' => ($isRent ? 'Apartamento para alugar em ' : 'Imóvel à venda em ') . $bairro,
                'cidade' => 'São Paulo',
                'bairro' => $bairro,
                'latitude' => $lat + (mt_rand(-3500, 3500) / 1000000),
                'longitude' => $lng + (mt_rand(-3500, 3500) / 1000000),
                'preco' => $preco,
                'tipo_negocio' => $isRent ? 'ALUGUEL' : 'VENDA',
                'tipo_imovel' => $tipo,
                'quartos' => $tipo === 'SALA_COMERCIAL' ? 0 : (($index % 4) + 1),
                'banheiros' => ($index % 3) + 1,
                'vagas' => $index % 4,
                'status' => 'ACTIVE',
                'account_id' => $accountId,
                'area_total' => 45 + (($index % 8) * 22),
                'score_qualidade' => 86 + ($index % 13),
                'is_destaque' => $index % 6 === 0,
                'highlight_level' => $index % 6 === 0 ? 1 : 0,
                'is_verified' => true,
                'publicado_em' => $now,
            ];
        }

        return $portfolio;
    }
}
