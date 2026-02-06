<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesToLeads extends Migration
{
    public function up()
    {
        // Leads indices
        $this->db->query("CREATE INDEX idx_leads_account ON leads(account_id_anunciante)");
        $this->db->query("CREATE INDEX idx_leads_property ON leads(property_id)");
        $this->db->query("CREATE INDEX idx_leads_status ON leads(status)");
        $this->db->query("CREATE INDEX idx_leads_created ON leads(created_at)");
        
        // Composite index for common filtering
        $this->db->query("CREATE INDEX idx_leads_account_status ON leads(account_id_anunciante, status)");
    }

    public function down()
    {
        $this->db->query("DROP INDEX IF EXISTS idx_leads_account");
        $this->db->query("DROP INDEX IF EXISTS idx_leads_property");
        $this->db->query("DROP INDEX IF EXISTS idx_leads_status");
        $this->db->query("DROP INDEX IF EXISTS idx_leads_created");
        $this->db->query("DROP INDEX IF EXISTS idx_leads_account_status");
    }
}
