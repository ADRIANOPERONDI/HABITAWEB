<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Fila de saída das integrações (hoje: leads indo para o CRM da origem).
 *
 * Por que não enviar direto no request do visitante: se a plataforma externa
 * estiver fora do ar, ou o lead se perde, ou o formulário do portal trava
 * esperando o timeout. Nenhum dos dois é aceitável — o lead é o produto.
 *
 * É também o que falta hoje no WebhookService, que entrega de forma síncrona e
 * sem retry: uma falha momentânea e a notificação some.
 *
 * Tabela, e não Redis (RedisEmailQueue): aqui a perda não é tolerável. A fila
 * de e-mail pode cair de volta para envio síncrono; um lead perdido é receita
 * perdida, e precisa sobreviver a restart e a queda do Redis.
 */
class CreateIntegrationOutboxTable extends Migration
{
    public function up()
    {
        $jsonType = $this->db->DBDriver === 'Postgre' ? 'JSONB' : 'JSON';

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_integration_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'event' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'comment'    => 'lead.created por enquanto',
            ],
            'reference_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'id da entidade de origem (leads.id), para exibir o selo na tela',
            ],
            'payload' => [
                'type'    => $jsonType,
                'null'    => true,
                'comment' => 'Já no formato que o conector espera',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'PENDING',
                'comment'    => 'PENDING, SENT, FAILED',
            ],
            'attempts' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'next_attempt_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'Backoff exponencial: 1min, 5min, 30min, 2h, 12h',
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'external_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'comment'    => 'Identificador devolvido pela origem no envio bem-sucedido',
            ],
            'sent_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        // O worker busca por (status, next_attempt_at) a cada minuto.
        $this->forge->addKey(['status', 'next_attempt_at']);
        $this->forge->addKey(['event', 'reference_id']);

        $this->forge->addForeignKey('account_integration_id', 'account_integrations', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('integration_outbox');
    }

    public function down()
    {
        $this->forge->dropTable('integration_outbox');
    }
}
