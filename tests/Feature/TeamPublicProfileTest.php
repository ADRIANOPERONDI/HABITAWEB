<?php

namespace Tests\Feature;

use App\Entities\User;
use App\Models\UserModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `admin/team/(:num)/update` (D5) — campos de perfil público (`publico`,
 * `cargo`, `bio`) que alimentam `AccountService::getPublicTeam()`. Até
 * aqui o controller só gravava `nome` e `role`: a seção de equipe da
 * página premium da imobiliária ficava sempre vazia, porque nenhuma tela
 * gravava `publico=true` em ninguém.
 *
 * @internal
 */
final class TeamPublicProfileTest extends HabitawebTestCase
{
    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    private function membro(int $accountId): User
    {
        $userModel = new UserModel();
        $sufixo    = substr(uniqid(), -8);

        $userModel->save(new User([
            'username'   => 'membro_' . $sufixo,
            'email'      => 'membro_' . $sufixo . '@teste.habitaweb.local',
            'password'   => 'MembroTeste#123',
            'active'     => 1,
            'account_id' => $accountId,
        ]));

        $membro = $userModel->find($userModel->getInsertID());
        $membro->addGroup('imobiliaria_corretor');

        return $membro;
    }

    public function testSalvaCamposPublicosDoMembro(): void
    {
        $tenant = (new TenantFactory())->create();
        $tenant['user']->addGroup('imobiliaria_admin');
        $membro = $this->membro((int) $tenant['account']->id);

        $this->actingAs($tenant['user'])->post('admin/team/' . $membro->id . '/update', $this->withCsrf([
            'nome'   => 'Corretora Teste',
            'role'   => 'imobiliaria_corretor',
            'publico' => '1',
            'cargo'  => 'Corretora Sênior',
            'bio'    => 'Especialista em imóveis de alto padrão.',
        ]));

        $atualizado = model(UserModel::class)->find($membro->id);
        $this->assertTrue((bool) $atualizado->publico);
        $this->assertSame('Corretora Sênior', $atualizado->cargo);
        $this->assertSame('Especialista em imóveis de alto padrão.', $atualizado->bio);
    }

    public function testDesmarcarPublicoRemoveDaVitrine(): void
    {
        $tenant = (new TenantFactory())->create();
        $tenant['user']->addGroup('imobiliaria_admin');
        $membro = $this->membro((int) $tenant['account']->id);

        model(UserModel::class)->update($membro->id, ['publico' => true, 'cargo' => 'Corretor']);

        $this->actingAs($tenant['user'])->post('admin/team/' . $membro->id . '/update', $this->withCsrf([
            'nome' => 'Corretor Teste',
            'role' => 'imobiliaria_corretor',
            // sem 'publico' — exatamente o que um checkbox desmarcado envia.
        ]));

        $atualizado = model(UserModel::class)->find($membro->id);
        $this->assertFalse((bool) $atualizado->publico);

        $publicos = (new \App\Services\AccountService())->getPublicTeam((int) $tenant['account']->id);
        $this->assertSame([], $publicos);
    }
}
