<?php

namespace Tests\Feature;

use App\Models\PaymentTransactionModel;
use App\Services\BillingReminderService;
use App\Services\NotificationService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Avisos de vencimento por e-mail: etapa certa para cada fatura, cada aviso
 * uma vez só, fatura paga não recebe aviso e fatura de leads não fala em
 * suspensão.
 *
 * @internal
 */
final class BillingReminderTest extends HabitawebTestCase
{
    /** @var list<array{account: int, fatura: int, etapa: string, suspende: bool}> */
    private array $enviados = [];
    private BillingReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('billing_reminders')->truncate();
        db_connect()->table('payment_transactions')->where('gateway_transaction_id LIKE', 'aviso_%')->delete();

        $teste    = $this;
        $notifier = new class ($teste) extends NotificationService {
            public function __construct(private BillingReminderTest $teste) { parent::__construct(); }
            public function notifyBillingReminder(object $account, object $fatura, string $etapa, bool $suspende): bool
            {
                $this->teste->registrarEnvio((int) $account->id, (int) $fatura->id, $etapa, $suspende);

                return true;
            }
        };
        $this->service = new BillingReminderService($notifier);
    }

    public function registrarEnvio(int $account, int $fatura, string $etapa, bool $suspende): void
    {
        $this->enviados[] = compact('account', 'fatura', 'etapa', 'suspende');
    }

    private function fatura(int $accountId, string $vencimento, string $status = 'PENDING', string $tipo = 'SUBSCRIPTION'): int
    {
        return (int) (new PaymentTransactionModel())->insert([
            'account_id' => $accountId, 'gateway' => 'asaas', 'gateway_transaction_id' => 'aviso_' . uniqid(),
            'amount' => 990.00, 'status' => $status, 'type' => $tipo, 'due_date' => $vencimento,
        ], true);
    }

    public function testEtapaPorDiasDeAtraso(): void
    {
        $this->assertNull(BillingReminderService::etapaPara(-4, true));
        $this->assertSame('antes_3', BillingReminderService::etapaPara(-3, true));
        $this->assertSame('antes_3', BillingReminderService::etapaPara(-1, true));
        $this->assertSame('vence_hoje', BillingReminderService::etapaPara(0, true));
        $this->assertSame('atraso_1', BillingReminderService::etapaPara(2, true));
        $this->assertSame('atraso_4', BillingReminderService::etapaPara(4, true));
        $this->assertSame('suspensa', BillingReminderService::etapaPara(5, true));
        $this->assertSame('suspensa', BillingReminderService::etapaPara(12, true));
        $this->assertSame('atraso_1', BillingReminderService::etapaPara(12, false), 'fatura de leads não fala em suspensão');
    }

    public function testCadaFaturaRecebeOAvisoDaEtapaUmaVezSo(): void
    {
        $conta = (int) (new TenantFactory())->create()['account']->id;
        $hoje  = '2026-11-10';
        $antes = $this->fatura($conta, '2026-11-13');
        $hojeF = $this->fatura($conta, '2026-11-10');
        $susp  = $this->fatura($conta, '2026-11-05');
        $paga  = $this->fatura($conta, '2026-11-10', 'CONFIRMED');
        $longe = $this->fatura($conta, '2026-11-20');

        $this->service->run($hoje);
        $porFatura = array_column($this->enviados, 'etapa', 'fatura');

        $this->assertSame('antes_3', $porFatura[$antes]);
        $this->assertSame('vence_hoje', $porFatura[$hojeF]);
        $this->assertSame('suspensa', $porFatura[$susp]);
        $this->assertArrayNotHasKey($paga, $porFatura, 'fatura paga não recebe aviso');
        $this->assertArrayNotHasKey($longe, $porFatura, 'vencimento a mais de 3 dias ainda não recebe aviso');

        $this->enviados = [];
        $this->service->run($hoje);
        $this->assertSame([], $this->enviados, 'rodar de novo no mesmo dia não repete aviso');

        // Dia seguinte: a fatura que vencia hoje passa a "1 dia de atraso".
        $this->service->run('2026-11-11');
        $this->assertSame([[ 'account' => $conta, 'fatura' => $hojeF, 'etapa' => 'atraso_1', 'suspende' => true ]], $this->enviados);
    }

    public function testDryRunNaoRegistra(): void
    {
        $conta = (int) (new TenantFactory())->create()['account']->id;
        $this->fatura($conta, '2026-11-10');

        $this->assertCount(1, $this->service->run('2026-11-10', true));
        $this->assertSame([], $this->enviados);
        $this->assertSame(0, db_connect()->table('billing_reminders')->countAllResults());
    }

    public function testEmailRenderizadoENotificacaoNoPainel(): void
    {
        $tenant = (new TenantFactory())->create();
        $conta  = model(\App\Models\AccountModel::class)->find($tenant['account']->id);
        $fatura = (object) ['id' => 1, 'amount' => 990, 'due_date' => '2026-11-05', 'description' => 'Assinatura Plano Prata', 'invoice_url' => 'https://pagar.test/fatura/1'];

        $capturado = null;
        $notifier  = new class ($capturado) extends NotificationService {
            public function __construct(private &$capturado) { parent::__construct(); }
            public function sendEmail(string $to, string $subject, string $message, bool $immediate = false): bool
            {
                $this->capturado = compact('to', 'subject', 'message');

                return true;
            }
        };

        $this->assertTrue($notifier->notifyBillingReminder($conta, $fatura, 'suspensa', true));
        $this->assertSame($conta->email, $capturado['to']);
        $this->assertSame('Seus anúncios foram suspensos por atraso', $capturado['subject']);
        $html = html_entity_decode($capturado['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('href="https://pagar.test/fatura/1"', $html);
        $this->assertStringContainsString('R$ 990,00', $html);
        $this->assertStringContainsString('volta automaticamente', $html);

        $this->assertSame(1, model(\App\Models\NotificationModel::class)->where('user_id', $tenant['user']->id)->where('title', 'Anúncios suspensos')->countAllResults());
    }
}
