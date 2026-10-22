<?php

namespace App\Libraries\Billing;

/**
 * Dia útil bancário nacional — vencimento da fatura mensal (dia 10, ou o
 * próximo dia útil se cair em fim de semana ou feriado, decisão do cliente).
 *
 * Feriados nacionais fixos e os móveis que fecham bancos (Carnaval segunda e
 * terça, Sexta-feira Santa, Corpus Christi), calculados a partir da Páscoa.
 * Feriados estaduais/municipais não entram: o boleto é compensado pela rede
 * nacional.
 */
final class BusinessDay
{
    private const FIXOS = ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'];

    public static function isBusinessDay(\DateTimeInterface $dia): bool
    {
        if ((int) $dia->format('N') >= 6) {
            return false;
        }

        return ! in_array($dia->format('Y-m-d'), self::holidays((int) $dia->format('Y')), true);
    }

    /** O próprio dia, se útil; senão o próximo dia útil. */
    public static function nextBusinessDay(string $data): string
    {
        $dia = new \DateTimeImmutable($data);
        while (! self::isBusinessDay($dia)) {
            $dia = $dia->modify('+1 day');
        }

        return $dia->format('Y-m-d');
    }

    /** @return list<string> datas Y-m-d */
    public static function holidays(int $ano): array
    {
        $pascoa = self::easter($ano);
        $moveis = [
            $pascoa->modify('-48 days'), // Carnaval (segunda)
            $pascoa->modify('-47 days'), // Carnaval (terça)
            $pascoa->modify('-2 days'),  // Sexta-feira Santa
            $pascoa->modify('+60 days'), // Corpus Christi
        ];

        return array_merge(
            array_map(static fn (string $md) => "{$ano}-{$md}", self::FIXOS),
            array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $moveis)
        );
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, sem a extensão calendar). */
    public static function easter(int $ano): \DateTimeImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
    }
}
