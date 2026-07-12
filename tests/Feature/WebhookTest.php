<?php

namespace Tests\Feature;

use Tests\Support\HabitawebTestCase;

/**
 * Cobre App\Controllers\Web\WebhookController::asaas() — o endpoint principal de
 * webhook, protegido pelo header asaas-access-token (H7 da auditoria) — e a
 * deduplicação de eventos repetidos via webhook_logs (event_type + event_id).
 *
 * O token de teste vem de ASAAS_WEBHOOK_TOKEN em phpunit.xml.dist (fixo, não é
 * segredo de produção — só existe no ambiente de teste).
 */
final class WebhookTest extends HabitawebTestCase
{
    private const VALID_TOKEN = 'phpunit-fixed-webhook-token';

    public function testMissingTokenHeaderIsRejected(): void
    {
        $this->withBodyFormat('json')
            ->post('asaas/webhook', ['event' => 'TEST_EVENT'])
            ->assertStatus(401);
    }

    public function testWrongTokenHeaderIsRejected(): void
    {
        $this->withHeaders(['asaas-access-token' => 'token-errado'])
            ->withBodyFormat('json')
            ->post('asaas/webhook', ['event' => 'TEST_EVENT'])
            ->assertStatus(401);
    }

    public function testValidTokenWithRecognizedEventIsAccepted(): void
    {
        $result = $this->withHeaders(['asaas-access-token' => self::VALID_TOKEN])
            ->withBodyFormat('json')
            ->post('asaas/webhook', ['event' => 'TEST_EVENT', 'id' => 'evt_' . bin2hex(random_bytes(6))]);

        $result->assertOK();
        $result->assertJSONFragment(['received' => true]);
    }

    /**
     * O achado H7 sobre idempotência: reenviar o MESMO evento (mesmo event_type +
     * event_id — cenário real de retry do gateway) não deve reprocessar nem criar
     * um segundo registro em webhook_logs.
     */
    public function testReplayingSameEventIsMarkedDuplicateAndNotLoggedTwice(): void
    {
        $eventId = 'evt_' . bin2hex(random_bytes(6));
        $client  = $this->withHeaders(['asaas-access-token' => self::VALID_TOKEN])->withBodyFormat('json');

        $first = $client->post('asaas/webhook', ['event' => 'TEST_EVENT', 'id' => $eventId]);
        $first->assertOK();
        $first->assertJSONFragment(['received' => true]);

        $second = $client->post('asaas/webhook', ['event' => 'TEST_EVENT', 'id' => $eventId]);
        $second->assertOK();
        $second->assertJSONFragment(['received' => true, 'duplicate' => true]);

        $this->seeNumRecords(1, 'webhook_logs', [
            'event_type' => 'asaas.TEST_EVENT',
            'event_id'   => $eventId,
        ]);
    }

    public function testDifferentEventIdsAreNotTreatedAsDuplicates(): void
    {
        $client = $this->withHeaders(['asaas-access-token' => self::VALID_TOKEN])->withBodyFormat('json');

        $client->post('asaas/webhook', ['event' => 'TEST_EVENT', 'id' => 'evt_a_' . bin2hex(random_bytes(4))])
            ->assertJSONFragment(['received' => true]);

        $second = $client->post('asaas/webhook', ['event' => 'TEST_EVENT', 'id' => 'evt_b_' . bin2hex(random_bytes(4))]);
        $second->assertOK();

        $body = json_decode($second->getJSON(), true);
        $this->assertArrayNotHasKey('duplicate', $body);
    }
}
