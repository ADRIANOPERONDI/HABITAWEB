<?php

if (! function_exists('short_price')) {
    /**
     * Formata um valor em Reais de forma curta para marcadores de mapa (sem o
     * "R$"). Abaixo de 10 mil o valor sai exato ("2.500"): arredondar 2.500
     * para "3 mil" muda a percepção do preço de um aluguel em 20%. De 10 mil
     * a 999 mil usa "mil" com até 1 casa ("12,5 mil", "350 mil") e de 1 milhão
     * em diante "mi" com até 2 casas ("1,25 mi"), sempre sem zeros sobrando.
     */
    function short_price(float $price): string
    {
        $trim = static fn (float $v, int $dec): string => rtrim(rtrim(number_format($v, $dec, ',', '.'), '0'), ',');

        if ($price < 10000) {
            return number_format($price, 0, ',', '.');
        }

        // 999.960 arredondaria para "1.000 mil": nesse caso já é 1 milhão.
        if ($price < 1000000 && round($price / 1000, 1) < 1000) {
            return $trim($price / 1000, 1) . ' mil';
        }

        return $trim($price / 1000000, 2) . ' mi';
    }
}

if (! function_exists('map_price_label')) {
    /**
     * Texto completo do marcador de preço do mapa: o mesmo critério de
     * `price_label()` (zero/ausente = "Sob consulta", nunca "R$ 0"), mas na
     * forma curta e sem o sufixo "/mês" que não cabe no pin.
     */
    function map_price_label(?float $preco): string
    {
        if ($preco === null || $preco <= 0) {
            return 'Sob consulta';
        }

        return 'R$ ' . short_price($preco);
    }
}

if (! function_exists('price_label')) {
    /**
     * Rótulo público de preço de imóvel. Preço zerado ou ausente não é
     * "grátis" — é o anunciante (ou o Simob, num imóvel sem valor cadastrado
     * na origem) não ter informado o valor —, e mostrar "R$ 0,00" na vitrine
     * passa a impressão errada. Aluguel ganha o sufixo "/mês" embutido, pra
     * não repetir o mesmo `if ($tipo === 'ALUGUEL')` em cada view.
     */
    function price_label(?float $preco, string $tipoNegocio): string
    {
        if ($preco === null || $preco <= 0) {
            return 'Sob consulta';
        }

        $label = 'R$ ' . number_format($preco, 2, ',', '.');

        return $tipoNegocio === 'ALUGUEL' ? $label . '/mês' : $label;
    }
}
