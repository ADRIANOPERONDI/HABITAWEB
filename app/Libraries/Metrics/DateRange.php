<?php

namespace App\Libraries\Metrics;

/**
 * Intervalo de datas fechado [de, até], inclusive nas duas pontas — a
 * unidade que o painel por período usa para consultar as tabelas diárias e
 * para calcular o período de comparação (as setas ↑↓).
 */
final class DateRange
{
    public function __construct(
        public readonly string $de,
        public readonly string $ate,
    ) {
    }

    public static function lastDays(int $days): self
    {
        $days = max(1, $days);

        return new self(date('Y-m-d', strtotime('-' . ($days - 1) . ' days')), date('Y-m-d'));
    }

    public static function currentMonth(): self
    {
        return new self(date('Y-m-01'), date('Y-m-d'));
    }

    public function days(): int
    {
        return (int) round((strtotime($this->ate) - strtotime($this->de)) / 86400) + 1;
    }

    /**
     * Período imediatamente anterior, de mesma duração — base da comparação
     * "vs. período anterior" (as setas ↑↓ do painel).
     */
    public function previous(): self
    {
        $dias        = $this->days();
        $ateAnterior = date('Y-m-d', strtotime($this->de . ' -1 day'));
        $deAnterior  = date('Y-m-d', strtotime($ateAnterior . ' -' . ($dias - 1) . ' days'));

        return new self($deAnterior, $ateAnterior);
    }

    /** @return string[] Todas as datas do intervalo, 'Y-m-d', em ordem. */
    public function dates(): array
    {
        $out     = [];
        $cursor  = strtotime($this->de);
        $fimTime = strtotime($this->ate);

        while ($cursor <= $fimTime) {
            $out[]  = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $out;
    }
}
