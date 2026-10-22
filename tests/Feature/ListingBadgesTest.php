<?php

namespace Tests\Feature;

use App\Models\PropertyMediaModel;
use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Selos da página do imóvel: "Anúncio completo" segue a regra por tipo
 * (ListingCompleteness) e cada selo traz a explicação exibida ao tocar.
 *
 * @internal
 */
final class ListingBadgesTest extends HabitawebTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->accountId = (int) (new TenantFactory())->create()['account']->id;
    }

    private function imovel(array $over, int $fotos): int
    {
        $id = (int) (new PropertyModel())->insert(array_merge([
            'account_id' => $this->accountId, 'titulo' => 'Casa Selo', 'status' => 'ACTIVE', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'CASA', 'cidade' => 'Cidade Selo', 'bairro' => 'Centro', 'preco' => 700000,
            'latitude' => -26.7, 'longitude' => -53.5, 'descricao' => 'Casa ampla.', 'area_total' => 180,
            'quartos' => 3, 'banheiros' => 2,
        ], $over), true);

        $media = new PropertyMediaModel();
        for ($i = 0; $i < $fotos; $i++) {
            $media->insert(['property_id' => $id, 'url' => "uploads/selo-{$id}-{$i}.jpg", 'tipo' => 'IMAGEM', 'ordem' => $i, 'principal' => $i === 0]);
        }
        PublicPropertyVisibilityService::invalidateCaches();

        return $id;
    }

    private function html(int $id): string
    {
        // esc(..., 'attr') codifica até os espaços do texto do popover.
        return html_entity_decode($this->get("imovel/{$id}")->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testCasaComCincoFotosEDadosGanhaOSeloComExplicacao(): void
    {
        $html = $this->html($this->imovel([], 5));

        $this->assertStringContainsString('Anúncio completo', $html);
        $this->assertStringContainsString('pelo menos 5 fotos, área, quartos e banheiros', $html);
        $this->assertMatchesRegularExpression('/data-bs-toggle="popover"[^>]*data-bs-content="Sua mensagem pelo formulário/', $html);
        $this->assertStringContainsString('Contato direto com a imobiliária', $html);
        $this->assertStringNotContainsString('Atendimento rastreado', $html);
        // A inicialização não pode depender do bloco do WhatsApp, que só é
        // renderizado quando o anunciante libera contato.
        $this->assertStringContainsString('new bootstrap.Popover(el)', $html);
    }

    public function testCasaComQuatroFotosNaoGanhaOSelo(): void
    {
        $this->assertStringNotContainsString('Anúncio completo', $this->html($this->imovel([], 4)));
    }

    public function testImovelSobConsultaNaoGanhaOSelo(): void
    {
        $this->assertStringNotContainsString('Anúncio completo', $this->html($this->imovel(['preco' => 0], 6)));
    }

    /** O selo de contato diz com quem o visitante fala, conforme o tipo da conta. */
    public function testSeloDeContatoUsaOTipoDaConta(): void
    {
        $esperado = [
            'CONSTRUTORA' => 'Contato direto com a construtora',
            'CORRETOR'    => 'Contato direto com o corretor',
            'PF'          => 'Contato direto com o proprietário',
        ];
        foreach ($esperado as $tipo => $texto) {
            $this->accountId = (int) (new TenantFactory())->create(['tipo_conta' => $tipo])['account']->id;
            $html = $this->html($this->imovel([], 1));
            $this->assertStringContainsString($texto, $html, $tipo);
        }
    }
}
