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
