<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Config\Factories;

class PropertyDemoSeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();
        
        // Contas criadas no MainSeeder
        $emails = [
            'admin@habitaweb.com',
            'joao@corretor.com',
            'ana@usuario.com'
        ];

        $propertyModel = Factories::models(\App\Models\PropertyModel::class);
        $faker = \Faker\Factory::create('pt_BR');

        $geoData = [
            'São Paulo' => [
                'lat' => -23.5505,
                'lng' => -46.6333,
                'bairros' => ['Centro', 'Bela Vista', 'Pinheiros', 'Vila Madalena', 'Itaim Bibi', 'Mooca', 'Tatuapé', 'Jardins']
            ],
            'Rio de Janeiro' => [
                'lat' => -22.9068,
                'lng' => -43.1729,
                'bairros' => ['Centro', 'Copacabana', 'Ipanema', 'Leblon', 'Barra da Tijuca', 'Botafogo', 'Flamengo', 'Tijuca']
            ],
            'Porto Alegre' => [
                'lat' => -30.0346,
                'lng' => -51.2177,
                'bairros' => ['Centro Histórico', 'Moinhos de Vento', 'Petrópolis', 'Menino Deus', 'Auxiliadora', 'Bela Vista', 'Tristeza']
            ],
            'Belo Horizonte' => [
                'lat' => -19.9191,
                'lng' => -43.9378,
                'bairros' => ['Centro', 'Savassi', 'Lourdes', 'Anchieta', 'Funcionários', 'Pampulha', 'Sion', 'Belvedere']
            ],
            'Curitiba' => [
                'lat' => -25.4290,
                'lng' => -49.2671,
                'bairros' => ['Centro', 'Batel', 'Bigorrilho', 'Água Verde', 'Portão', 'Cabral', 'Cristo Rei', 'Santa Felicidade']
            ],
            'São Miguel do Oeste' => [
                'lat' => -26.7323,
                'lng' => -53.5186,
                'bairros' => ['Centro', 'Agostini', 'Industrial', 'São Jorge', 'Estrela', 'Salete', 'Andreatta']
            ]
        ];

        $tipos   = ['APARTAMENTO', 'CASA', 'LOJA', 'SALA_COMERCIAL', 'TERRENO'];
        $negocios = ['VENDA', 'ALUGUEL'];

        foreach ($emails as $email) {
            $user = $userModel->findByCredentials(['email' => $email]);
            
            if (!$user) {
                echo "Usuário $email não encontrado. Pulando...\n";
                continue;
            }

            $accountId = $user->account_id;
            echo "Gerando imóveis para $email (Conta ID: $accountId)...\n";

            for ($i = 0; $i < 5; $i++) {
                $tipo = $faker->randomElement($tipos);
                $negocio = $faker->randomElement($negocios);
                $cidade = $faker->randomElement(array_keys($geoData));
                $cidadeInfo = $geoData[$cidade];
                $bairro = $faker->randomElement($cidadeInfo['bairros']);
                
                // Calcula coordenadas com dispersão (ruído de até ±0.015 graus)
                $latOffset = (mt_rand(-15000, 15000) / 1000000);
                $lngOffset = (mt_rand(-15000, 15000) / 1000000);
                $lat = $cidadeInfo['lat'] + $latOffset;
                $lng = $cidadeInfo['lng'] + $lngOffset;
                
                $preco = ($negocio == 'VENDA') ? $faker->numberBetween(250000, 3000000) : $faker->numberBetween(1200, 12000);
                
                $data = [
                    'account_id'      => $accountId,
                    'user_id_responsavel' => $user->id,
                    'titulo'          => $this->generateTitle($tipo, $bairro, $negocio),
                    'descricao'       => $faker->paragraph(4),
                    'tipo_imovel'     => $tipo,
                    'tipo_negocio'    => $negocio,
                    'preco'           => $preco,
                    'area_total'      => $faker->numberBetween(40, 400),
                    'quartos'         => $faker->numberBetween(1, 4),
                    'banheiros'       => $faker->numberBetween(1, 3),
                    'vagas'           => $faker->numberBetween(0, 2),
                    'cep'             => $faker->postcode,
                    'cidade'          => $cidade,
                    'estado'          => $faker->stateAbbr,
                    'bairro'          => $bairro,
                    'rua'             => $faker->streetName,
                    'numero'          => $faker->buildingNumber,
                    'latitude'        => $lat,
                    'longitude'       => $lng,
                    'status'          => 'ACTIVE',
                    'score_qualidade' => $faker->numberBetween(70, 100),
                    'publicado_em'    => date('Y-m-d H:i:s'),
                ];

                if ($propertyModel->save($data)) {
                    $propId = $propertyModel->getInsertID();

                    // Adicionar Fotos de Demonstração
                    for ($j = 0; $j < 4; $j++) {
                        $imgUrl = "https://picsum.photos/seed/prop_{$propId}_{$j}/800/600";
                        
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
            }
            echo "\n";
        }

        echo "Seeder de demonstração concluído com sucesso!\n";
    }

    private function generateTitle($tipo, $bairro, $negocio) {
        $adjs = ['Incrível', 'Excelente', 'Moderno', 'Espaçoso', 'Luxuoso', 'Oportunidade única:'];
        $adj = $adjs[array_rand($adjs)];
        $tipoStr = ucfirst(strtolower($tipo));
        
        return "$adj $tipoStr no bairro $bairro para " . strtolower($negocio);
    }
}
