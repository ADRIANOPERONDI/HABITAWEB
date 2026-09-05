<?php

namespace Tests\Unit\Integrations;

use App\Entities\AccountIntegration;
use App\Libraries\Integrations\Dto\SyncCursor;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SyncCursorTest extends TestCase
{
    public function testForceFullIgnoraOUltimoSync(): void
    {
        $integration = new AccountIntegration(['last_sync_at' => '2026-08-15 10:00:00']);

        $cursor = SyncCursor::fromIntegration($integration, true);

        $this->assertNull($cursor->since);
    }

    public function testSemUltimoSyncVarreTudo(): void
    {
        $integration = new AccountIntegration(['last_sync_at' => null]);

        $cursor = SyncCursor::fromIntegration($integration);

        $this->assertNull($cursor->since);
    }

    /**
     * A folga de 24h é o que impede uma deriva de relógio entre este
     * servidor e a origem de descartar, em silêncio, um item atualizado
     * pouco depois do "início" registrado da rodada anterior.
     */
    public function testAplicaMargemDeVinteQuatroHorasContraDerivaDeRelogio(): void
    {
        $integration = new AccountIntegration(['last_sync_at' => '2026-08-15 10:00:00']);

        $cursor = SyncCursor::fromIntegration($integration);

        $this->assertSame('2026-08-14 10:00:00', $cursor->since);
    }

    public function testIsBeforeUsaOCorteComMargem(): void
    {
        $integration = new AccountIntegration(['last_sync_at' => '2026-08-15 10:00:00']);
        $cursor      = SyncCursor::fromIntegration($integration);

        // Sem a margem, este item (do mesmo dia do último sync) teria sido
        // descartado como "já sincronizado". Com a margem de 24h, o corte
        // real é 2026-08-14 10:00:00 — o item continua sendo buscado.
        $this->assertFalse($cursor->isBefore('2026-08-15 09:00:00'));

        // Item genuinamente anterior ao corte com margem continua sendo pulado.
        $this->assertTrue($cursor->isBefore('2026-08-14 09:00:00'));
    }
}
