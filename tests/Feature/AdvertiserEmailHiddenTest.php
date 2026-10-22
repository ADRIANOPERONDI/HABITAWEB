<?php

namespace Tests\Feature;

use App\Models\AccountModel;
use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * O e-mail do anunciante não aparece no site: quem escreve por fora não passa
 * pelo formulário, e só o contato pelo site vira lead (item 11 da revisão).
 *
 * @internal
 */
final class AdvertiserEmailHiddenTest extends HabitawebTestCase
{
    public function testEmailDoAnuncianteNaoApareceNoImovelNemNoPerfil(): void
    {
        cache()->clean();
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $email     = 'contato-oculto-' . bin2hex(random_bytes(3)) . '@imobiliaria.test';
        model(AccountModel::class)->update($accountId, ['email' => $email]);

        $id = (int) (new PropertyModel())->insert([
            'account_id' => $accountId, 'titulo' => 'Imóvel Email', 'status' => 'ACTIVE', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'casa', 'cidade' => 'Cidade Email', 'bairro' => 'Centro', 'preco' => 100000,
        ], true);
        PublicPropertyVisibilityService::invalidateCaches();

        $slug = (string) model(AccountModel::class)->find($accountId)->slug;

        foreach (["imovel/{$id}", 'imobiliaria/' . $slug] as $uri) {
            $html = $this->get($uri)->response()->getBody();
            $this->assertStringNotContainsString($email, $html, $uri);
            $this->assertStringNotContainsString('mailto:', $html, $uri);
            $this->assertStringNotContainsString('href="tel:', $html, $uri);
            $this->assertStringNotContainsString('fa-phone me-2', $html, $uri);
        }
    }
}
