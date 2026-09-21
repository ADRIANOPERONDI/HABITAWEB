<?php

namespace Tests\Feature;

use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * O formulário de imóvel renderiza com o que a geocodificação automática
 * precisa. Trava as três coisas que estavam faltando e que fizeram o cadastro
 * manual gravar coordenada errada (ou nenhuma) em silêncio.
 */
final class PropertyFormGeocodeTest extends HabitawebTestCase
{
    private function abrirFormulario()
    {
        $tenant = (new TenantFactory())->create();

        return $this->actingAs($tenant['user'])->get('admin/properties/new');
    }

    /**
     * A UF é o que separa São Miguel do Oeste/SC de São Miguel do Araguaia/GO.
     * Sem o campo, todo imóvel cadastrado à mão nascia com estado NULL.
     */
    public function testTemCampoDeUf(): void
    {
        $r = $this->abrirFormulario();

        $r->assertOK();
        $this->assertStringContainsString('name="estado"', $r->getBody());
        $this->assertStringContainsString('>SC<', $r->getBody());
    }

    /**
     * Silêncio era o pior resultado: sem feedback, o corretor salvava sem
     * coordenada sem saber que o geocoder tinha falhado.
     */
    public function testTemAreaDeFeedbackDaGeocodificacao(): void
    {
        $r = $this->abrirFormulario();

        $r->assertOK();
        $this->assertStringContainsString('geocode-status', $r->getBody());
    }

    /** O navegador não pode mais montar a própria escada de consultas. */
    public function testNaoChamaMaisONominatimDiretoDoNavegador(): void
    {
        $r = $this->abrirFormulario();

        $r->assertOK();
        $this->assertStringNotContainsString(
            'nominatim.openstreetmap.org',
            $r->getBody(),
            'o formulário voltou a bater direto no Nominatim, com a escada antiga sem UF',
        );
        $this->assertStringContainsString('admin/properties/geocode', $r->getBody());
    }

    /**
     * Imóvel novo não pode abrir com o marcador na Praça da Sé: um arraste
     * acidental antes de digitar o endereço gravava São Paulo como posição.
     */
    public function testImovelNovoNaoAbreOMapaEmSaoPaulo(): void
    {
        $r = $this->abrirFormulario();

        $r->assertOK();
        $this->assertStringNotContainsString('-23.55052', $r->getBody());
        $this->assertStringNotContainsString('-46.633308', $r->getBody());
    }
}
