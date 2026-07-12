<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;
use Tests\Support\TestUploadedFile;

/**
 * Cobre App\Services\PropertyService::checkPlanLimits() (limite de imóveis ativos
 * por plano) e addMedia() (validação real de upload: MIME por finfo, decodificação
 * de imagem, extensão forçada — o que impede um script disfarçado de imagem virar
 * um arquivo .php executável no diretório de uploads).
 */
final class PropertyLimitTest extends HabitawebTestCase
{
    private const FIXTURES = __DIR__ . '/../_support/fixtures';

    /**
     * addMedia() grava arquivo físico em public/uploads/properties/{id}/ — fora
     * da transação de banco, então o rollback do teste NÃO limpa isso sozinho.
     * Sem essa limpeza, cada execução da suíte deixa diretórios de lixo aqui.
     */
    private array $uploadDirsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->uploadDirsToClean as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob("{$dir}/*") ?: []);
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    private function insertProperty(int $accountId, array $overrides = []): int
    {
        $model = new PropertyModel();
        $model->insert(array_merge([
            'account_id'   => $accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel de teste',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ], $overrides));

        return (int) $model->getInsertID();
    }

    /** Cria um plano com limite baixo e uma assinatura ACTIVE nesse plano para o tenant. */
    private function subscribeToLimitedPlan(int $accountId, ?int $limit): void
    {
        $planModel = new PlanModel();
        $planModel->insert([
            'chave'                 => 'LIMIT_TEST_' . bin2hex(random_bytes(3)),
            'nome'                  => 'Plano de Teste',
            'limite_imoveis_ativos' => $limit,
            'preco_mensal'          => 100,
            'ativo'                 => true,
        ]);
        $planId = $planModel->getInsertID();

        \Config\Database::connect()->table('subscriptions')
            ->where('account_id', $accountId)
            ->delete();

        (new SubscriptionModel())->insert([
            'account_id'  => $accountId,
            'plan_id'     => $planId,
            'status'      => 'ACTIVE',
            'data_inicio' => date('Y-m-d'),
            'data_fim'    => date('Y-m-d', strtotime('+1 year')),
        ]);
    }

    public function testActivatingPropertyWithoutSubscriptionIsBlocked(): void
    {
        $tenant = (new TenantFactory())->create();
        \Config\Database::connect()->table('subscriptions')->where('account_id', $tenant['account']->id)->delete();

        $result = (new PropertyService())->checkPlanLimits($tenant['account']->id);

        $this->assertFalse($result['allowed']);
    }

    public function testActivatingPropertyWithCancelledSubscriptionIsBlocked(): void
    {
        $tenant = (new TenantFactory())->create();
        \Config\Database::connect()->table('subscriptions')
            ->where('account_id', $tenant['account']->id)
            ->update(['status' => 'CANCELLED']);

        $result = (new PropertyService())->checkPlanLimits($tenant['account']->id);

        $this->assertFalse($result['allowed']);
    }

    public function testPlanLimitBlocksActivationAtCapacity(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->subscribeToLimitedPlan($tenant['account']->id, limit: 1);
        $this->insertProperty($tenant['account']->id); // já ocupa a única vaga

        $result = (new PropertyService())->checkPlanLimits($tenant['account']->id);

        $this->assertFalse($result['allowed']);
    }

    public function testPlanLimitAllowsActivationBelowCapacity(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->subscribeToLimitedPlan($tenant['account']->id, limit: 2);
        $this->insertProperty($tenant['account']->id);

        $result = (new PropertyService())->checkPlanLimits($tenant['account']->id);

        $this->assertTrue($result['allowed']);
    }

    public function testNullLimitMeansUnlimited(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->subscribeToLimitedPlan($tenant['account']->id, limit: null);
        for ($i = 0; $i < 5; $i++) {
            $this->insertProperty($tenant['account']->id);
        }

        $result = (new PropertyService())->checkPlanLimits($tenant['account']->id);

        $this->assertTrue($result['allowed']);
    }

    public function testStaffBypassesPlanLimitRegardlessOfCapacity(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->subscribeToLimitedPlan($tenant['account']->id, limit: 1);
        $this->insertProperty($tenant['account']->id);

        $result = (new PropertyService())->checkPlanLimits($tenant['account']->id, null, true);

        $this->assertTrue($result['allowed']);
    }

    public function testValidJpegImageIsAccepted(): void
    {
        $tenant = (new TenantFactory())->create();
        $propertyId = $this->insertProperty($tenant['account']->id);

        $tmp = sys_get_temp_dir() . '/' . uniqid('valid_', true) . '.jpg';
        copy(self::FIXTURES . '/valid.jpg', $tmp);
        $file = new TestUploadedFile($tmp, 'foto.jpg', 'image/jpeg', filesize($tmp), UPLOAD_ERR_OK);
        $this->uploadDirsToClean[] = FCPATH . 'uploads/properties/' . $propertyId;

        $result = (new PropertyService())->addMedia($propertyId, $file);
        @unlink($tmp);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertDatabaseHas('property_media', ['property_id' => $propertyId]);
    }

    /**
     * O núcleo da correção C5 da auditoria: um arquivo PHP disfarçado de .jpg
     * (extensão do cliente falsificada) precisa ser rejeitado pelo MIME real
     * (finfo lido do CONTEÚDO), não pela extensão nem pelo Content-Type enviado.
     */
    public function testScriptDisguisedAsImageIsRejected(): void
    {
        $tenant = (new TenantFactory())->create();
        $propertyId = $this->insertProperty($tenant['account']->id);

        $tmp = sys_get_temp_dir() . '/' . uniqid('malicious_', true) . '.jpg';
        copy(self::FIXTURES . '/malicious.jpg', $tmp);
        // Content-Type forjado como imagem — a validação real não deve confiar nisso.
        $file = new TestUploadedFile($tmp, 'foto.jpg', 'image/jpeg', filesize($tmp), UPLOAD_ERR_OK);
        $this->uploadDirsToClean[] = FCPATH . 'uploads/properties/' . $propertyId;

        $result = (new PropertyService())->addMedia($propertyId, $file);
        @unlink($tmp);

        $this->assertFalse($result['success']);
        $this->assertDatabaseMissing('property_media', ['property_id' => $propertyId]);
    }
}
