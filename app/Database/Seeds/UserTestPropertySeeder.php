<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\I18n\Time;

class UserTestPropertySeeder extends Seeder
{
    public function run()
    {
        $data = [
            'account_id'          => 15, // User from logs
            'tipo_negocio'        => 'ALUGUEL',
            'tipo_imovel'         => 'CASA',
            'titulo'              => 'Casa de Luxo para Teste Turbo',
            'descricao'           => 'Casa incrível criada para testar funcionalidades de destaque e turbo.',
            'preco'               => 15000.00,
            'area_total'          => 350,
            'quartos'             => 4,
            'banheiros'           => 5,
            'vagas'               => 4,
            'cep'                 => '04551-010', // Vila Olimpia
            'estado'              => 'SP',
            'cidade'              => 'São Paulo',
            'bairro'              => 'Vila Olímpia',
            'status'              => 'ACTIVE',
            'is_destaque'         => 0, // Start clean
            'highlight_level'     => 0,
            'score_qualidade'     => 85, // Good score
            'created_at'          => Time::now(),
            'updated_at'          => Time::now(),
            'publicado_em'        => Time::now(),
        ];

        // Insert Property
        $this->db->table('properties')->insert($data);
        $propId = $this->db->insertID();

        // Insert a dummy image
        $this->db->table('property_media')->insert([
            'property_id' => $propId,
            'url'         => 'https://images.unsplash.com/photo-1493809842364-78817add7ffb?auto=format&fit=crop&w=800&q=80',
            'tipo'        => 'IMAGE',
            'principal'   => 1,
            'created_at'  => Time::now(),
        ]);

        echo "Property $propId created for Account 15.\n";
    }
}
