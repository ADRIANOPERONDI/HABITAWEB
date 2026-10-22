<?php

namespace Tests\Unit;

use App\Libraries\Billing\BusinessDay;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BusinessDayTest extends CIUnitTestCase
{
    public function testPascoaConhecida(): void
    {
        $this->assertSame('2026-04-05', BusinessDay::easter(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', BusinessDay::easter(2027)->format('Y-m-d'));
        $this->assertSame('2024-03-31', BusinessDay::easter(2024)->format('Y-m-d'));
    }

    /**
     * @dataProvider vencimentos
     */
    public function testDiaDezVaiParaOProximoDiaUtil(string $dia10, string $esperado): void
    {
        $this->assertSame($esperado, BusinessDay::nextBusinessDay($dia10));
    }

    public static function vencimentos(): iterable
    {
        yield 'terça útil'               => ['2026-11-10', '2026-11-10'];
        yield 'sábado, segunda é feriado (12/out)' => ['2026-10-10', '2026-10-13'];
        yield 'domingo vai para segunda' => ['2027-01-10', '2027-01-11'];
        yield 'Carnaval 2027 (9/fev)'    => ['2027-02-09', '2027-02-10'];
        yield 'Tiradentes 2027 (qua)'    => ['2027-04-21', '2027-04-22'];
    }
}
