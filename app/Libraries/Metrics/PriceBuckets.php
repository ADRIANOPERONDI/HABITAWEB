<?php

namespace App\Libraries\Metrics;

/**
 * Escadas fixas de faixa de preço, por tipo de negócio — bucketizar na
 * entrada é o que segura a cardinalidade de `search_daily` (preço cru
 * geraria uma linha por centavo buscado).
 */
class PriceBuckets
{
    private const VENDA = [
        200000  => '0-200k',
        350000  => '200-350k',
        500000  => '350-500k',
        750000  => '500-750k',
        1000000 => '750k-1M',
        2000000 => '1M-2M',
    ];

    private const VENDA_ACIMA = '2M+';

    private const ALUGUEL = [
        1000 => '0-1000',
        2000 => '1000-2000',
        3500 => '2000-3500',
        5000 => '3500-5000',
        8000 => '5000-8000',
    ];

    private const ALUGUEL_ACIMA = '8000+';

    /** Bucket de um preço concreto (o de um imóvel). */
    public static function bucketFor(string $tipoNegocio, ?float $preco): string
    {
        if ($preco === null || $preco <= 0) {
            return 'QUALQUER';
        }

        $aluguel  = in_array($tipoNegocio, ['ALUGUEL', 'TEMPORADA'], true);
        $escadas  = $aluguel ? self::ALUGUEL : self::VENDA;
        $acimaLbl = $aluguel ? self::ALUGUEL_ACIMA : self::VENDA_ACIMA;

        foreach ($escadas as $teto => $label) {
            if ($preco <= $teto) {
                return $label;
            }
        }

        return $acimaLbl;
    }

    /**
     * Bucket representativo de uma BUSCA — não há um preço único, só um
     * intervalo. Usa o teto (`max_price`, a régua de orçamento do
     * visitante) quando existe; senão o piso; senão "QUALQUER" (busca sem
     * filtro de preço).
     */
    public static function bucketForSearch(string $tipoNegocio, $minPrice, $maxPrice): string
    {
        if ($maxPrice !== null && $maxPrice !== '') {
            return self::bucketFor($tipoNegocio, (float) $maxPrice);
        }

        if ($minPrice !== null && $minPrice !== '') {
            return self::bucketFor($tipoNegocio, (float) $minPrice);
        }

        return 'QUALQUER';
    }
}
