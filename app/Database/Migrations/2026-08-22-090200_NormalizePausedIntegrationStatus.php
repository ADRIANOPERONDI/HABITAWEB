<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Corrige integrações que ficaram com status='PAUSED' antes de
 * IntegrationService::toggleActive() parar de escrever esse valor.
 *
 * status passa a ser só saúde da CONEXÃO (CONNECTED/ERROR/PENDING); pausa é
 * representada exclusivamente por is_active=false. Uma linha com
 * status='PAUSED' tinha uma credencial que funcionava (foi testada com
 * sucesso antes de ser pausada) — volta pra CONNECTED, não pra PENDING.
 */
class NormalizePausedIntegrationStatus extends Migration
{
    public function up()
    {
        $this->db->query(
            "UPDATE account_integrations SET status = 'CONNECTED' WHERE status = 'PAUSED'"
        );
    }

    public function down()
    {
        // Irreversível de propósito: não há como saber, depois do up(), quais
        // linhas eram 'PAUSED' antes — e não faria sentido reintroduzir um
        // valor que o código não escreve mais.
    }
}
