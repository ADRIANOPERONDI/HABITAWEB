<?php

namespace Tests\Unit\Integrations;

use App\Libraries\Integrations\Dto\ExternalImage;
use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Dto\SyncResult;
use App\Models\IntegrationSyncRunModel;
use PHPUnit\Framework\TestCase;

/**
 * Os DTOs carregam as duas decisões que fazem o sync ser barato:
 * o hash que evita UPDATE desnecessário e o corte incremental por data.
 *
 * @internal
 */
final class IntegrationDtoTest extends TestCase
{
    private function property(array $fields, array $images = [], array $raw = []): ExternalProperty
    {
        return new ExternalProperty('3376', $fields, $images, '3364', '2026-08-01 10:00:00', $raw);
    }

    // ------------------------------------------------------------ contentHash

    public function testHashEEstavelEntreChamadas(): void
    {
        $p = $this->property(['titulo' => 'Casa', 'preco' => 350000]);

        $this->assertSame($p->contentHash(), $p->contentHash());
    }

    /**
     * A origem não garante ordem de campo entre chamadas. Se o hash dependesse
     * disso, o sync marcaria "mudou" em imóvel intocado e reescreveria o
     * catálogo inteiro toda rodada.
     */
    public function testHashIgnoraAOrdemDosCampos(): void
    {
        $a = $this->property(['titulo' => 'Casa', 'preco' => 350000, 'quartos' => 3]);
        $b = $this->property(['quartos' => 3, 'preco' => 350000, 'titulo' => 'Casa']);

        $this->assertSame($a->contentHash(), $b->contentHash());
    }

    public function testHashIgnoraAOrdemDasImagens(): void
    {
        $a = $this->property(['titulo' => 'Casa'], [
            new ExternalImage('https://cdn/a.jpg', 1, true),
            new ExternalImage('https://cdn/b.jpg', 2),
        ]);
        $b = $this->property(['titulo' => 'Casa'], [
            new ExternalImage('https://cdn/b.jpg', 2),
            new ExternalImage('https://cdn/a.jpg', 1, true),
        ]);

        $this->assertSame($a->contentHash(), $b->contentHash());
    }

    public function testHashMudaQuandoUmCampoMuda(): void
    {
        $a = $this->property(['titulo' => 'Casa', 'preco' => 350000]);
        $b = $this->property(['titulo' => 'Casa', 'preco' => 360000]);

        $this->assertNotSame($a->contentHash(), $b->contentHash());
    }

    public function testHashMudaQuandoEntraUmaImagem(): void
    {
        $a = $this->property(['titulo' => 'Casa'], [new ExternalImage('https://cdn/a.jpg')]);
        $b = $this->property(['titulo' => 'Casa'], [
            new ExternalImage('https://cdn/a.jpg'),
            new ExternalImage('https://cdn/b.jpg'),
        ]);

        $this->assertNotSame($a->contentHash(), $b->contentHash());
    }

    /**
     * O payload cru do Simob traz contador de visitas e valores recalculados na
     * hora. Se entrassem no hash, nenhum imóvel seria pulado nunca.
     */
    public function testHashIgnoraOPayloadCru(): void
    {
        $a = $this->property(['titulo' => 'Casa'], [], ['visitas' => 10, 'geradoEm' => '10:00']);
        $b = $this->property(['titulo' => 'Casa'], [], ['visitas' => 998, 'geradoEm' => '11:47']);

        $this->assertSame($a->contentHash(), $b->contentHash());
    }

    // ------------------------------------------------------------- SyncCursor

    public function testCursorSemCorteNuncaConsideraNadaAntigo(): void
    {
        $cursor = new SyncCursor(null);

        $this->assertFalse($cursor->isBefore('2020-01-01 00:00:00'));
    }

    public function testCursorCortaOQueEAnteriorOuIgual(): void
    {
        $cursor = new SyncCursor('2026-08-01 12:00:00');

        $this->assertTrue($cursor->isBefore('2026-07-31 23:59:59'), 'anterior ao corte');
        $this->assertTrue($cursor->isBefore('2026-08-01 12:00:00'), 'igual ao corte');
        $this->assertFalse($cursor->isBefore('2026-08-01 12:00:01'), 'posterior ao corte');
    }

    /**
     * Item sem data na origem não pode ser tratado como antigo: seria pulado
     * para sempre e nunca entraria no catálogo.
     */
    public function testItemSemDataNuncaEConsideradoAntigo(): void
    {
        $cursor = new SyncCursor('2026-08-01 12:00:00');

        $this->assertFalse($cursor->isBefore(null));
        $this->assertFalse($cursor->isBefore(''));
    }

    public function testDataIlegivelNaoCortaOItem(): void
    {
        $cursor = new SyncCursor('2026-08-01 12:00:00');

        $this->assertFalse($cursor->isBefore('não é data'));
    }

    // ------------------------------------------------------------- SyncResult

    public function testRodadaLimpaESUCCESS(): void
    {
        $r = new SyncResult();
        $r->created = 5;

        $this->assertSame(IntegrationSyncRunModel::STATUS_SUCCESS, $r->status());
        $this->assertNull($r->errorSummary());
    }

    /**
     * Erro em item isolado não invalida a rodada: quem sincronizou,
     * sincronizou. PARTIAL diz exatamente isso.
     */
    public function testErroEmItemViraPARTIALNaoERROR(): void
    {
        $r = new SyncResult();
        $r->created = 10;
        $r->addError('imóvel 42: preço inválido');

        $this->assertSame(IntegrationSyncRunModel::STATUS_PARTIAL, $r->status());
        $this->assertStringContainsString('imóvel 42', $r->errorSummary());
    }

    public function testLimiteDePlanoViraPARTIALComMensagemPropria(): void
    {
        $r                   = new SyncResult();
        $r->updated          = 30;
        $r->planLimitReached = true;

        $this->assertSame(IntegrationSyncRunModel::STATUS_PARTIAL, $r->status());
        $this->assertStringContainsString('Limite de imóveis do plano', $r->errorSummary());
    }

    /**
     * Catálogo grande e quebrado não pode encher a coluna error_message nem a
     * memória do processo.
     */
    public function testGuardaSoAsPrimeirasMensagensMasContaTodas(): void
    {
        $r = new SyncResult();

        for ($i = 1; $i <= 50; $i++) {
            $r->addError("erro {$i}");
        }

        $this->assertSame(50, $r->errors);
        $this->assertStringContainsString('+30 erro(s) não listado(s)', $r->errorSummary());
    }

    public function testContadoresSaemNoFormatoDaTabela(): void
    {
        $r          = new SyncResult();
        $r->created = 2;
        $r->images  = 7;

        $counters = $r->toCounters();

        $this->assertSame(2, $counters['created_count']);
        $this->assertSame(7, $counters['images_count']);
        $this->assertArrayHasKey('paused_count', $counters);
    }
}
