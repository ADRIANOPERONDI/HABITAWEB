<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDetailedFieldsToProperties extends Migration
{
    public function up()
    {
        $fields = [
            'suites' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'after'      => 'quartos'
            ],
            'is_destaque' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'status'
            ],
            'is_novo' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'is_destaque'
            ],
            'meta_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'descricao'
            ],
            'meta_description' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'meta_title'
            ],
            'is_exclusivo' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'is_novo'
            ],
            'aceita_pets' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'is_exclusivo'
            ],
            'mobiliado' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'aceita_pets'
            ],
            'semimobiliado' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'mobiliado'
            ],
            'is_desocupado' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'semimobiliado'
            ],
            'is_locado' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'is_desocupado'
            ],
            'renda_mensal_estimada' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'default'    => 0,
                'after'      => 'preco'
            ],
            'indicado_investidor' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'indicado_temporada'
            ],
            'indicado_primeira_moradia' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'indicado_investidor'
            ],
            'indicado_temporada' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'is_locado'
            ],
        ];

        $this->forge->addColumn('properties', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('properties', [
            'suites', 'is_destaque', 'is_novo', 'meta_title', 'meta_description', 
            'is_exclusivo', 'aceita_pets', 'mobiliado', 'semimobiliado', 
            'is_desocupado', 'is_locado', 'renda_mensal_estimada', 
            'indicado_investidor', 'indicado_primeira_moradia', 'indicado_temporada'
        ]);
    }
}
