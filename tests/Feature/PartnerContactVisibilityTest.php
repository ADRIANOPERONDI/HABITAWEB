<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Mesma regra de PublicPropertyVisibilityTest (contato exige KYC aprovado +
 * assinatura ativa), aplicada à página pública da imobiliária
 * (`imobiliaria/(:segment)`) — que tem seu próprio botão de WhatsApp/telefone,
 * independente do da página de detalhe do imóvel.
 */
final class PartnerContactVisibilityTest extends HabitawebTestCase
{
    private function property(int $accountId): void
    {
        (new PropertyModel())->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Parceiro Contato',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 400000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ]);
    }

    public function testWhatsappETelefoneOcultosNaPaginaDoParceiroSemOnboardingCompleto(): void
    {
        $tenant = (new TenantFactory())->create(['verification_status' => 'PENDING', 'is_verified' => false]);
        $accountId = (int) $tenant['account']->id;
        $this->property($accountId);
        $slug = $tenant['account']->slug;

        $response = $this->get('imobiliaria/' . $slug);
        $response->assertOK();
        $response->assertDontSee('11999990000');
    }

    /**
     * Parceiro liberado: o botão do WhatsApp aparece, mas o telefone não fica
     * escrito nem vira link "tel:" — ligação direta não passa pela plataforma
     * e não vira lead (decisão do cliente).
     */
    public function testWhatsappVisivelETelefoneOcultoNaPaginaDoParceiroOnboarded(): void
    {
        $tenant = (new TenantFactory())->create(['whatsapp' => '49988887777']);
        $accountId = (int) $tenant['account']->id;
        $this->property($accountId);
        $slug = $tenant['account']->slug;

        $response = $this->get('imobiliaria/' . $slug);
        $response->assertOK();
        $response->assertSee('wa.me/49988887777');
        $response->assertDontSee('11999990000');
        $response->assertDontSee('tel:');
    }
}
