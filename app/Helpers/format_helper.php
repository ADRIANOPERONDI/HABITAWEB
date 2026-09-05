<?php

if (! function_exists('short_price')) {
    /**
     * Formata um valor em Reais de forma curta para exibição em marcadores de
     * mapa/pins (ex.: 450000 -> "450 mil", 1200000 -> "1,2 mi"). Sem o "R$".
     */
    function short_price(float $price): string
    {
        if ($price >= 1000000) {
            return number_format($price / 1000000, $price >= 10000000 ? 0 : 1, ',', '.') . ' mi';
        }

        if ($price >= 1000) {
            return number_format($price / 1000, 0, ',', '.') . ' mil';
        }

        return number_format($price, 0, ',', '.');
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
