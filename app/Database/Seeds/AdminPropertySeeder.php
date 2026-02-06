<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Config\Factories;

class AdminPropertySeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();
        // Tenta pegar id 1 ou pelo email
        $user = $userModel->findById(1);
        if (!$user || $user->email !== 'admin@imob.com') {
            $user = $userModel->findByCredentials(['email' => 'admin@imob.com']);
        }

        if (!$user) {
            fwrite(STDERR, "Usuário admin@imob.com não encontrado. Rode o MainSeeder primeiro.\n");
            return;
        }

        $accountId = $user->account_id;
        if (!$accountId) {
            fwrite(STDERR, "Usuário não possui account_id vinculado.\n");
            return;
        }

        $propertyModel = Factories::models(\App\Models\PropertyModel::class);
        $mediaModel    = Factories::models(\App\Models\PropertyMediaModel::class);

        $faker = \Faker\Factory::create('pt_BR');

        $bairros = ['Centro', 'Jardim Paulista', 'Copacabana', 'Moinhos de Vento', 'Savassi', 'Barra da Tijuca', 'Vila Madalena', 'Itaim Bibi'];
        $cidades = ['São Paulo', 'Rio de Janeiro', 'Porto Alegre', 'Belo Horizonte', 'Curitiba'];
        $tipos   = ['APARTAMENTO', 'CASA', 'LOJA', 'SALA_COMERCIAL', 'TERRENO'];
        $negocios = ['VENDA', 'ALUGUEL'];

        for ($i = 0; $i < 20; $i++) {
            $tipo = $faker->randomElement($tipos);
            $negocio = $faker->randomElement($negocios);
            $cidade = $faker->randomElement($cidades);
            $bairro = $faker->randomElement($bairros);
            
            $preco = ($negocio == 'VENDA') ? $faker->numberBetween(200000, 5000000) : $faker->numberBetween(1500, 15000);
            
            $data = [
                'account_id' => $accountId,
                'titulo'     => $this->generateTitle($tipo, $bairro, $negocio),
                'descricao'  => $faker->paragraph(3),
                'tipo_imovel'=> $tipo,
                'tipo_negocio'=> $negocio,
                'preco'      => $preco,
                'area_total' => $faker->numberBetween(30, 500),
                'quartos'    => $faker->numberBetween(1, 5),
                'banheiros'  => $faker->numberBetween(1, 4),
                'vagas'      => $faker->numberBetween(0, 3),
                'cep'        => $faker->postcode,
                'cidade'     => $cidade,
                'estado'     => $faker->stateAbbr,
                'bairro'     => $bairro,
                'rua'        => $faker->streetName,
                'numero'     => $faker->buildingNumber,
                'status'     => 'ACTIVE',
                'destaque'   => $faker->boolean(20), // 20% chance de destaque
                'score_qualidade' => $faker->numberBetween(50, 100),
                'highlight_level' => $faker->numberBetween(0, 3) // Alguns já turbinados
            ];

            // Salva propriedade
            if ($propertyModel->save($data)) {
                $propId = $propertyModel->getInsertID();

                // Adiciona Mídias (Fotos)
                $numFotos = $faker->numberBetween(3, 8);
                for ($j = 0; $j < $numFotos; $j++) {
                    // Usar placeholder service para imagens realistas
                    // Ex: https://placehold.co/600x400/EEE/31343C?text=Foto+Imovel
                    // Ou LoremFlickr (Architecture)
                    $width = 800;
                    $height = 600;
                    $randomId = $faker->numberBetween(1, 1000);
                    // Usando picsum ou similar que é estável
                    $imgUrl = "https://picsum.photos/seed/{$propId}{$j}/{$width}/{$height}";

                // Usar Builder direto para evitar bug do Model com Postgres
                $item = [
                    'property_id' => $propId,
                    'url'         => $imgUrl,
                    'tipo'        => 'FOTO',
                    'principal'   => ($j === 0) ? 't' : 'f', // Postgres boolean
                    'ordem'       => $j,
                    'created_at'  => date('Y-m-d H:i:s')
                ];
                $this->db->table('property_media')->insert($item);
                }
                
                echo ".";
            }
        }

        echo "\n20 Imóveis criados para admin@imob.com!\n";
    }

    private function generateTitle($tipo, $bairro, $negocio) {
        $adjs = ['Lindo', 'Espaçoso', 'Moderno', 'Aconchegante', 'Luxuoso', 'Oportunidade:'];
        $adj = $adjs[array_rand($adjs)];
        $tipoStr = ucfirst(strtolower($tipo));
        
        return "$adj $tipoStr no $bairro para " . strtolower($negocio);
    }
}
