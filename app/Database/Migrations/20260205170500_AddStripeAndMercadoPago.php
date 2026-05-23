<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStripeAndMercadoPago extends Migration
{
    public function up()
    {
        $data = [
            [
                'code' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Pagamentos via Stripe (Cartão)',
                'class_name' => 'App\\PaymentGateways\\StripeGateway',
                'is_active' => true,
                'is_primary' => false,
                'supported_methods' => json_encode(['CREDIT_CARD']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'code' => 'mercadopago',
                'name' => 'Mercado Pago',
                'description' => 'Pagamentos via Mercado Pago',
                'class_name' => 'App\\PaymentGateways\\MercadoPagoGateway',
                'is_active' => true,
                'is_primary' => false,
                'supported_methods' => json_encode(['CREDIT_CARD', 'PIX', 'BOLETO']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];

        foreach ($data as $row) {
            $existing = $this->db->table('payment_gateways')->where('code', $row['code'])->get()->getRow();
            if (!$existing) {
                $this->db->table('payment_gateways')->insert($row);
            }
        }
    }

    public function down()
    {
        $this->db->table('payment_gateways')->whereIn('code', ['stripe', 'mercadopago'])->delete();
    }
}
