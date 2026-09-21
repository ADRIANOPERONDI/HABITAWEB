<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marca a coordenada que o usuário posicionou à mão, arrastando o pino no mapa.
 *
 * Sem isso não há como distinguir um pino colocado pelo corretor de uma
 * coordenada calculada pelo geocoder — e `spark imoveis:geocodificar` faz
 * UPDATE direto na tabela, sobrescrevendo em silêncio o ajuste manual. Para
 * endereço rural ou de loteamento novo, que o OpenStreetMap não conhece, esse
 * ajuste é a ÚNICA forma de o imóvel aparecer no lugar certo: perdê-lo no
 * próximo lote é perder a informação de vez.
 *
 * Default false cobre as linhas existentes: nenhuma foi ajustada à mão até
 * aqui, então todas seguem elegíveis à regeocodificação.
 */
class AddCoordenadasManuaisToProperties extends Migration
{
    public function up()
    {
        $this->forge->addColumn('properties', [
            'coordenadas_manuais' => [
                'type'    => 'BOOLEAN',
                'null'    => false,
                'default' => false,
                'after'   => 'longitude',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('properties', 'coordenadas_manuais');
    }
}
