<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run()
    {
        // Garante dados minimos necessarios para suites legadas.
        if ($this->db->tableExists('plans') && class_exists(PlanSeeder::class)) {
            $this->call(PlanSeeder::class);
        }

        if ($this->db->tableExists('payment_gateways') && class_exists(PaymentGatewaysSeeder::class)) {
            $this->call(PaymentGatewaysSeeder::class);
        }
    }
}
