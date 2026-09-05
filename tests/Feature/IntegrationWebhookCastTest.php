<?php

namespace Tests\Feature;

use App\Models\IntegrationWebhookModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o cast de `is_active` em IntegrationWebhookModel.
 *
 * Havia duas classes `BooleanCast` diferentes no CI4: uma para cast de MODEL
 * (`CodeIgniter\DataCaster\Cast\BooleanCast`, trata 't'/'f' do Postgres) e outra
 * para cast de ENTITY pura (`CodeIgniter\Entity\Cast\BooleanCast`, faz só
 * `(bool) $value`). Como `IntegrationWebhookModel` não declarava `$casts`,
 * `useCasts()` do model voltava false e a leitura ia direto para a hidratação
 * da Entity — passando pela classe errada. `(bool) 'f'` é `true` em PHP (string
 * não vazia), então todo webhook DESATIVADO era lido como ativo.
 *
 * Achado ao depurar TurboService::deactivateExpired(), que tem o mesmo formato
 * (Model sem $casts + Entity com cast booleano) — PromotionModel recebeu o
 * mesmo tratamento.
 */
final class IntegrationWebhookCastTest extends HabitawebTestCase
{
    public function testWebhookDesativadoNaoVoltaComoAtivo(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;

        $model = model(IntegrationWebhookModel::class);
        $model->insert([
            'account_id' => $accountId,
            'name'       => 'Webhook de teste',
            'event'      => 'lead.created',
            'target_url' => 'https://exemplo.com/hook',
            'secret'     => 'segredo',
            'is_active'  => false,
        ]);
        $id = $model->getInsertID();

        $webhook = $model->find($id);

        $this->assertFalse(
            (bool) $webhook->is_active,
            "'f' do Postgres é string não vazia — (bool) 'f' é true em PHP. É esse exato bug que o cast no model existe para evitar."
        );
        $this->assertIsBool($webhook->is_active);
    }

    public function testWebhookAtivoContinuaAtivo(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;

        $model = model(IntegrationWebhookModel::class);
        $model->insert([
            'account_id' => $accountId,
            'name'       => 'Webhook ativo',
            'event'      => 'lead.created',
            'target_url' => 'https://exemplo.com/hook',
            'secret'     => 'segredo',
            'is_active'  => true,
        ]);
        $id = $model->getInsertID();

        $this->assertTrue((bool) $model->find($id)->is_active);
    }
}
