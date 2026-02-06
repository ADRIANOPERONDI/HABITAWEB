<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class NormalizeStatusValues extends Migration
{
    public function up()
    {
        // Normalizar Contas
        $this->db->query("UPDATE accounts SET status = 'ACTIVE' WHERE status IN ('ATIVO', 'active')");
        $this->db->query("UPDATE accounts SET status = 'INACTIVE' WHERE status IN ('INATIVO', 'inactive')");
        $this->db->query("UPDATE accounts SET status = 'SUSPENDED' WHERE status = 'suspended'");
        
        // Normalizar Subscriptions
        $this->db->query("UPDATE subscriptions SET status = 'ACTIVE' WHERE status = 'ATIVA'");
        
        // Normalizar Properties (só para garantir consistência)
        $this->db->query("UPDATE properties SET status = 'ACTIVE' WHERE status = 'ativo'");
    }

    public function down()
    {
        // Não há como reverter com precisão total sem saber o valor original exato, 
        // mas como 'ATIVO' era o padrão anterior:
        $this->db->query("UPDATE accounts SET status = 'ATIVO' WHERE status = 'ACTIVE'");
        $this->db->query("UPDATE subscriptions SET status = 'ATIVA' WHERE status = 'ACTIVE'");
    }
}
