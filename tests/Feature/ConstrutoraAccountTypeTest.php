<?php

namespace Tests\Feature;

use App\Entities\Account;
use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Tipo de conta CONSTRUTORA (pedido do cliente): aparece no cadastro, no
 * perfil público e no anúncio com o rótulo certo.
 *
 * @internal
 */
final class ConstrutoraAccountTypeTest extends HabitawebTestCase
{
    public function testRotulos(): void
    {
        $this->assertSame('Construtora', Account::tipoLabel('CONSTRUTORA'));
        $this->assertSame('a construtora', Account::tipoComArtigo('construtora'));
        $this->assertSame('Anunciante', Account::tipoLabel('XYZ'));
        $this->assertSame('o anunciante', Account::tipoComArtigo(null));
    }

    public function testCadastroOfereceConstrutora(): void
    {
        $html = $this->get('anuncie')->response()->getBody();

        $this->assertStringContainsString('<option value="CONSTRUTORA"', $html);
    }

    public function testPerfilEAnuncioMostramConstrutora(): void
    {
        cache()->clean();
        $tenant    = (new TenantFactory())->create(['tipo_conta' => 'CONSTRUTORA']);
        $accountId = (int) $tenant['account']->id;
        $id = (int) (new PropertyModel())->insert([
            'account_id' => $accountId, 'titulo' => 'Lançamento', 'status' => 'ACTIVE', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'apartamento', 'cidade' => 'Cidade Obra', 'bairro' => 'Centro', 'preco' => 400000,
        ], true);
        PublicPropertyVisibilityService::invalidateCaches();

        $perfil = $this->get('imobiliaria/' . $tenant['account']->slug)->response()->getBody();
        $this->assertMatchesRegularExpression('/badge bg-primary rounded-pill px-3">Construtora</', $perfil);

        $anuncio = html_entity_decode($this->get("imovel/{$id}")->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Contato direto com a construtora', $anuncio);
        $this->assertMatchesRegularExpression('/opacity-75" style="font-size: 10px;">Construtora</', $anuncio);
    }

    /** Construtora é empresa: entra com o mesmo grupo de permissão da imobiliária. */
    public function testCadastroDeConstrutoraGanhaGrupoDeEmpresa(): void
    {
        $sufixo = bin2hex(random_bytes(3));
        $user = (new \App\Services\AccountService())->registerUser([
            'nome'       => 'Construtora Teste ' . $sufixo,
            'tipo_conta' => 'CONSTRUTORA',
            'documento'  => '11222333000181',
            'email'      => "obra{$sufixo}@construtora.test",
            'password'   => 'SenhaForte#2026',
        ]);

        $this->assertTrue($user->inGroup('imobiliaria_admin'));
        $conta = model(\App\Models\AccountModel::class)->find($user->account_id);
        $this->assertSame('CONSTRUTORA', $conta->tipo_conta);
    }
}
