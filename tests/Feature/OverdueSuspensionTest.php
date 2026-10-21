<?php

namespace Tests\Feature;

use App\Models\LeadModel;
use App\Models\PaymentTransactionModel;
use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Suspensão por atraso (regra comercial, item 11 da revisão): passados
 * DIAS_ATRASO_SUSPENSAO dias de atraso, os anúncios saem do site e a conta
 * para de receber contatos novos; pago o atraso, tudo volta sozinho.
 *
 * @internal
 */
final class OverdueSuspensionTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
    }

    public function testAtrasoTiraOAnuncioDoArEBloqueiaContatoNovoEPagamentoReativa(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $id = (int) (new PropertyModel())->insert([
            'account_id' => $accountId, 'titulo' => 'Imóvel Suspenso', 'status' => 'ACTIVE', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'casa', 'cidade' => 'Cidade Suspensa', 'bairro' => 'Centro', 'preco' => 100000,
        ], true);

        $txModel = new PaymentTransactionModel();
        $txId = (int) $txModel->insert([
            'account_id' => $accountId, 'gateway' => 'asaas', 'gateway_transaction_id' => 'susp_' . uniqid(),
            'amount' => 100.00, 'status' => 'OVERDUE', 'type' => 'SUBSCRIPTION',
            'due_date' => date('Y-m-d', strtotime('-' . (PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO + 1) . ' days')),
        ], true);
        PublicPropertyVisibilityService::invalidateCaches();

        $this->get("imovel/{$id}")->assertStatus(404);

        $antes = model(LeadModel::class)->where('property_id', $id)->countAllResults();
        $resposta = $this->post('leads', [
            csrf_token()          => csrf_hash(),
            'property_id'         => $id,
            'nome_visitante'      => 'Visitante',
            'email_visitante'     => 'visitante@teste.local',
            'telefone_visitante'  => '49999990000',
            'mensagem'            => 'Tenho interesse',
        ]);
        $this->assertNotSame(200, $resposta->response()->getStatusCode(), 'conta suspensa não recebe contato novo');
        $this->assertSame($antes, model(LeadModel::class)->where('property_id', $id)->countAllResults());

        // Pagamento confirmado (webhook → CONFIRMED): volta sozinho.
        $txModel->update($txId, ['status' => 'CONFIRMED']);
        PublicPropertyVisibilityService::invalidateCaches();

        $this->get("imovel/{$id}")->assertOK();
    }
}
