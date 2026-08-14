<?php

namespace Tests\Feature;

use App\Models\AccountModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a etapa 5.3 da página da imobiliária: rota canônica por slug
 * (`imobiliaria/(:segment)`), o 301 de `parceiro/(:num)` para ela, e
 * `ProfileController::update()` aceitando os novos campos de perfil público
 * (endereço, bio, redes sociais) sem tocar no slug.
 */
final class PartnerProfilePageTest extends HabitawebTestCase
{
    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    public function testRotaPorSlugRendorizaAPaginaDoParceiro(): void
    {
        $tenant = (new TenantFactory())->create(['nome' => 'Imobiliaria Slug Teste ' . bin2hex(random_bytes(3))]);
        $account = model(AccountModel::class)->find($tenant['account']->id);

        $this->get('imobiliaria/' . $account->slug)
            ->assertOK();
    }

    public function testSlugDesconhecidoDevolve404(): void
    {
        $this->get('imobiliaria/nao-existe-' . bin2hex(random_bytes(4)))
            ->assertStatus(404);
    }

    public function testUrlLegadaPorIdRedirecionaComA301ParaOSlug(): void
    {
        $tenant = (new TenantFactory())->create(['nome' => 'Imobiliaria Legado ' . bin2hex(random_bytes(3))]);
        $account = model(AccountModel::class)->find($tenant['account']->id);

        $response = $this->get('parceiro/' . $account->id);

        $response->assertStatus(301);
        $response->assertRedirectTo('imobiliaria/' . $account->slug);
    }

    public function testProfileUpdateAceitaEnderecoBioERedesSociais(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->actingAs($tenant['user'])->post('admin/profile', $this->withCsrf([
            'nome'                 => $tenant['account']->nome,
            'documento'            => $tenant['account']->documento,
            'email'                => $tenant['account']->email,
            'cep'                  => '89800-000',
            'estado'               => 'SC',
            'cidade'               => 'Chapecó',
            'bairro'               => 'Centro',
            'rua'                  => 'Rua Teste',
            'numero'               => '123',
            'complemento'          => 'Sala 4',
            'descricao'            => 'Imobiliária de teste com mais de duas décadas de mercado.',
            'site'                 => 'https://exemplo.com.br',
            'horario_atendimento'  => 'Seg a Sex, 9h às 18h',
            'instagram'            => 'https://instagram.com/exemplo',
            'facebook'             => 'https://facebook.com/exemplo',
            'linkedin'             => 'https://linkedin.com/company/exemplo',
            'youtube'              => 'https://youtube.com/@exemplo',
            'tiktok'               => 'https://tiktok.com/@exemplo',
        ]));

        $account = model(AccountModel::class)->find($tenant['account']->id);

        $this->assertSame('89800-000', $account->cep);
        $this->assertSame('SC', $account->estado);
        $this->assertSame('Chapecó', $account->cidade);
        $this->assertSame('Centro', $account->bairro);
        $this->assertSame('Rua Teste', $account->rua);
        $this->assertSame('123', $account->numero);
        $this->assertSame('Sala 4', $account->complemento);
        $this->assertSame('Imobiliária de teste com mais de duas décadas de mercado.', $account->descricao);
        $this->assertSame('https://exemplo.com.br', $account->site);
        $this->assertSame('Seg a Sex, 9h às 18h', $account->horario_atendimento);
        $this->assertSame('https://instagram.com/exemplo', $account->instagram);
        $this->assertSame('https://facebook.com/exemplo', $account->facebook);
        $this->assertSame('https://linkedin.com/company/exemplo', $account->linkedin);
        $this->assertSame('https://youtube.com/@exemplo', $account->youtube);
        $this->assertSame('https://tiktok.com/@exemplo', $account->tiktok);
    }

    public function testProfileUpdateNuncaMudaOSlugMesmoTrocandoONome(): void
    {
        $tenant = (new TenantFactory())->create(['nome' => 'Nome Original ' . bin2hex(random_bytes(3))]);
        $account = model(AccountModel::class)->find($tenant['account']->id);
        $slugOriginal = $account->slug;

        $this->actingAs($tenant['user'])->post('admin/profile', $this->withCsrf([
            'nome'      => 'Nome Totalmente Diferente ' . bin2hex(random_bytes(3)),
            'documento' => $account->documento,
            'email'     => $account->email,
        ]));

        $atualizado = model(AccountModel::class)->find($tenant['account']->id);

        $this->assertSame($slugOriginal, $atualizado->slug, 'slug e imutavel apos a criacao -- mudar quebraria links ja divulgados');
        $this->assertNotSame($tenant['account']->nome, $atualizado->nome, 'sanity check: o nome de fato mudou');
    }
}
