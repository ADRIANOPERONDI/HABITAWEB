<?php

namespace Tests\Feature;

use App\Models\AccountModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `spark kyc:aprovar` — mesmo efeito do botão "Aprovar" em
 * admin/verification/show/(:num), só que pelo terminal, para destravar
 * uma conta do filtro `admin_auth` sem precisar abrir o painel.
 *
 * @internal
 */
final class ApproveKycCommandTest extends HabitawebTestCase
{
    private function runCommand(string $args): void
    {
        ob_start();
        command('kyc:aprovar ' . $args);
        ob_end_clean();
    }

    private function contaPendente(): array
    {
        $tenant = (new TenantFactory())->create();

        model(AccountModel::class)->update($tenant['account']->id, [
            'verification_status' => 'PENDING',
            'is_verified'         => false,
        ]);

        return $tenant;
    }

    public function testAprovaPorEmail(): void
    {
        $tenant = $this->contaPendente();

        $this->runCommand($tenant['user']->email);

        $conta = model(AccountModel::class)->find($tenant['account']->id);
        $this->assertSame('APPROVED', $conta->verification_status);
        $this->assertTrue((bool) $conta->is_verified);
    }

    public function testAprovaPorIdDaConta(): void
    {
        $tenant = $this->contaPendente();

        $this->runCommand((string) $tenant['account']->id);

        $conta = model(AccountModel::class)->find($tenant['account']->id);
        $this->assertSame('APPROVED', $conta->verification_status);
        $this->assertTrue((bool) $conta->is_verified);
    }

    public function testContaJaAprovadaNaoQuebra(): void
    {
        $tenant = (new TenantFactory())->create(); // verification_status APPROVED por default

        $this->runCommand($tenant['user']->email);

        $conta = model(AccountModel::class)->find($tenant['account']->id);
        $this->assertSame('APPROVED', $conta->verification_status);
    }

    public function testEmailInexistenteNaoAlteraNada(): void
    {
        $totalAntes = model(AccountModel::class)->countAllResults();

        $this->runCommand('ninguem-com-esse-email@teste.habitaweb.local');

        $this->assertSame($totalAntes, model(AccountModel::class)->countAllResults());
    }
}
