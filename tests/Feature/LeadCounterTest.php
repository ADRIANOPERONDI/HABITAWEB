<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\LeadService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o contador `properties.leads_count`.
 *
 * LeadService::incrementPropertyLeadCount fazia find() -> +1 em PHP -> save(),
 * o que perde contagem sob concorrência (dois leads no mesmo imóvel leem o mesmo
 * valor e gravam o mesmo resultado). O incremento passou a ser feito pelo banco.
 *
 * Ressalva honesta sobre o alcance destes testes: rodando em sequência, a versão
 * antiga e a nova produzem o mesmo número — a corrida só aparece com dois
 * processos concorrentes, que PHPUnit não reproduz aqui. O que estes testes
 * garantem é o contrato observável (cada lead distinto conta uma vez, duplicado
 * não conta, e o UPDATE não encosta em outras colunas), de modo que a troca do
 * incremento não passe despercebida numa regressão futura.
 *
 * Exercitam o caminho público (trySaveLead), que é quem chama o contador de
 * verdade — e de quebra prendem a deduplicação por e-mail, que na cobrança por
 * lead vira política de faturamento.
 */
final class LeadCounterTest extends HabitawebTestCase
{
    private int $accountId;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = (new TenantFactory())->create();
        $this->accountId = (int) $tenant['account']->id;

        $model = new PropertyModel();
        $model->insert([
            'account_id'   => $this->accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel para contagem de leads',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ]);
        $this->propertyId = (int) $model->getInsertID();
    }

    private function leadsCount(): int
    {
        return (int) (new PropertyModel())->find($this->propertyId)->leads_count;
    }

    public function testCadaLeadDistintoIncrementaOContador(): void
    {
        $service = new LeadService();

        for ($i = 1; $i <= 5; $i++) {
            $result = $service->trySaveLead([
                'property_id'        => $this->propertyId,
                'nome_visitante'     => "Visitante {$i}",
                'email_visitante'    => "visitante{$i}@exemplo.com",
                'telefone_visitante' => '49999990000',
                'mensagem'           => 'Tenho interesse.',
            ]);

            $this->assertTrue($result['success'], 'Lead ' . $i . ' deveria ter sido criado.');
        }

        $this->assertSame(5, $this->leadsCount());
    }

    public function testLeadDuplicadoDoMesmoEmailNaoIncrementa(): void
    {
        $service = new LeadService();

        $payload = [
            'property_id'        => $this->propertyId,
            'nome_visitante'     => 'Mesma Pessoa',
            'email_visitante'    => 'mesma@exemplo.com',
            'telefone_visitante' => '49999990000',
            'mensagem'           => 'Tenho interesse.',
        ];

        $service->trySaveLead($payload);
        $service->trySaveLead($payload);

        $this->assertSame(1, $this->leadsCount());
    }

    public function testIncrementoPreservaOsDemaisCamposDoImovel(): void
    {
        $model = new PropertyModel();
        $model->update($this->propertyId, ['leads_count' => 40, 'visitas_count' => 137]);

        (new LeadService())->trySaveLead([
            'property_id'        => $this->propertyId,
            'nome_visitante'     => 'Visitante',
            'email_visitante'    => 'visitante@exemplo.com',
            'telefone_visitante' => '49999990000',
            'mensagem'           => 'Tenho interesse.',
        ]);

        $property = (new PropertyModel())->find($this->propertyId);

        $this->assertSame(41, (int) $property->leads_count);
        $this->assertSame(137, (int) $property->visitas_count, 'O UPDATE não pode tocar em outros contadores.');
        $this->assertSame('Imóvel para contagem de leads', $property->titulo);
    }
}
