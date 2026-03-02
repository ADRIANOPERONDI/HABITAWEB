<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDueDateToPaymentTransactions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('payment_transactions', [
            'due_date' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'amount'
            ],
        ]);

        // Attempt to backfill due_date from metadata JSON
        // Using raw SQL because of PostgreSQL JSONB operators
        $db = \Config\Database::connect();
        
        // 1. Try to extract from direct JSON (normal case)
        $db->query("
            UPDATE payment_transactions 
            SET due_date = (metadata->>'dueDate')::DATE 
            WHERE due_date IS NULL AND metadata ? 'dueDate'
        ");

        // 2. Try to extract from double-encoded JSON string inside jsonb column
        // (Diagnostic showed some records have a JSON string as the root of jsonb)
        $db->query("
            UPDATE payment_transactions 
            SET due_date = ((metadata#>>'{}')::jsonb->>'dueDate')::DATE 
            WHERE due_date IS NULL 
            AND metadata#>>'{}' LIKE '%dueDate%'
        ");
        
        // 3. Fallback for those without dueDate but with created_at (estimate as today if pending)
        // This is a safety measure for blocking logic sanity
        $db->query("
            UPDATE payment_transactions 
            SET due_date = created_at::DATE 
            WHERE due_date IS NULL AND status IN ('PENDING', 'AWAITING_PAYMENT')
        ");
    }

    public function down()
    {
        $this->forge->dropColumn('payment_transactions', 'due_date');
    }
}
