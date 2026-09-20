<?php

namespace Tests\Feature;

use App\Database\Migrations\NormalizeCityCase;
use App\Models\PropertyModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * A migration que colapsa as grafias divergentes de `properties.cidade`.
 *
 * Vale um teste porque é uma alteração de dado de produção sem volta: o
 * down() é vazio de propósito, já que depois do up() não há como saber qual
 * imóvel estava em caixa alta. Se o de/para estiver errado, o estrago não se
 * desfaz.
 */
final class NormalizeCityCaseMigrationTest extends HabitawebTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (new TenantFactory())->create()['account']->id;
    }

    private function criar(string $cidade): int
    {
        return (int) (new PropertyModel())->insert([
            'account_id'   => $this->accountId,
            'titulo'       => 'Imóvel ' . bin2hex(random_bytes(3)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => $cidade,
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
            'preco'        => 300000,
        ], true);
    }

    private function cidadeDe(int $id): ?string
    {
        return (new PropertyModel())->find($id)->cidade;
    }

    private function migrar(): void
    {
        // O nome do arquivo de migration carrega o prefixo de data, então não
        // casa com PSR-4 e o autoloader não acha a classe — o MigrationRunner
        // do framework também inclui na mão.
        require_once APPPATH . 'Database/Migrations/2026-09-20-120000_NormalizeCityCase.php';

        // O construtor de Migration deriva $this->db do forge — sem passar o
        // do grupo de teste, a migration rodaria no banco padrão.
        (new NormalizeCityCase(\Config\Database::forge()))->up();
    }

    /** O caso do cliente: 79 imóveis em caixa alta e 11 na forma canônica. */
    public function testColapsaAsDuasGrafiasNaFormaCanonica(): void
    {
        $caixaAlta = $this->criar('SÃO MIGUEL DO OESTE');
        $canonica  = $this->criar('São Miguel do Oeste');

        $this->migrar();

        $this->assertSame('São Miguel do Oeste', $this->cidadeDe($caixaAlta));
        $this->assertSame('São Miguel do Oeste', $this->cidadeDe($canonica));
    }

    /** Preposição em minúscula — o de/para não pode virar "São Miguel Do Oeste". */
    public function testPreposicaoNaoEeCapitalizada(): void
    {
        $id = $this->criar('RIO DE JANEIRO');

        $this->migrar();

        $this->assertSame('Rio de Janeiro', $this->cidadeDe($id));
    }

    /** Rodar duas vezes não pode mudar nada na segunda — cron/deploy repetem. */
    public function testEIdempotente(): void
    {
        $id = $this->criar('GUARACIABA');

        $this->migrar();
        $primeira = $this->cidadeDe($id);
        $this->migrar();

        $this->assertSame('Guaraciaba', $primeira);
        $this->assertSame($primeira, $this->cidadeDe($id));
    }

    /** Cidade vazia é dado ruim, mas a migration não pode explodir por causa dela. */
    public function testCidadeVaziaNaoQuebra(): void
    {
        $id = $this->criar('');

        $this->migrar();

        $this->assertSame('', $this->cidadeDe($id));
    }

    /** Depois da migration o dropdown mostra a cidade uma vez só. */
    public function testDropdownFicaComUmaLinhaPorCidade(): void
    {
        $this->criar('SÃO MIGUEL DO OESTE');
        $this->criar('São Miguel do Oeste');

        $this->migrar();

        $distintas = \Config\Database::connect()
            ->table('properties')
            ->select('cidade')
            ->distinct()
            ->where('LOWER(cidade)', 'são miguel do oeste')
            ->get()
            ->getResultArray();

        $this->assertCount(1, $distintas);
        $this->assertSame('São Miguel do Oeste', $distintas[0]['cidade']);
    }
}
