<?php

namespace App\Libraries\Text;

/**
 * Forma canônica e chave de comparação de um bairro.
 *
 * Mesmo problema da cidade (ver CityName): `properties.bairro` é texto livre,
 * o Simob manda "CENTRO" e o formulário admin "Centro", e o filtro da busca
 * mostrava as duas opções. Aqui a regra é a mesma de CityName (Title Case com
 * preposição em minúscula, espaços colapsados) mais numeral romano em
 * maiúscula ("Loteamento Ii" não existe: é "Loteamento II").
 *
 * Duas funções com papéis diferentes:
 *  - normalize(): o que se GRAVA e se MOSTRA.
 *  - key(): o que se COMPARA. Ignora caixa, acento e espaço extra, de modo que
 *    "SAO JORGE" e "São  Jorge" são o mesmo bairro. Nunca junta nomes
 *    realmente distintos ("Centro" e "Centro Histórico" têm chaves diferentes);
 *    para esses existe o alias manual (neighborhood_aliases).
 */
final class NeighborhoodName
{
    private const ROMANOS = ['II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII', 'XIII', 'XIV', 'XV'];

    /**
     * Começos de texto que descrevem um ponto de referência, não um bairro.
     * Só serve para avisar — nunca para descartar o valor automaticamente.
     */
    private const PREFIXOS_REFERENCIA = [
        'proximo a', 'proximo ao', 'proximo ', 'perto d', 'perto de', 'ao lado', 'em frente', 'frente a', 'frente ao',
        'atras d', 'atras de', 'proximidades', 'proximidade ',
    ];

    public static function normalize(?string $bairro): ?string
    {
        if ($bairro === null) {
            return null;
        }

        $normalizado = CityName::normalize($bairro);

        if ($normalizado === null || $normalizado === '') {
            return $normalizado;
        }

        $palavras = explode(' ', $normalizado);
        foreach ($palavras as $i => $palavra) {
            $maiuscula = mb_strtoupper($palavra, 'UTF-8');
            if (in_array($maiuscula, self::ROMANOS, true)) {
                $palavras[$i] = $maiuscula;
            }
        }

        return implode(' ', $palavras);
    }

    /** Chave de comparação: minúscula, sem acento, espaços colapsados. */
    public static function key(?string $texto): string
    {
        if ($texto === null) {
            return '';
        }

        $limpo = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $texto) ?? ''), 'UTF-8');

        return strtr($limpo, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /**
     * O valor parece um ponto de referência ("Próximo a Agropecuária Terra
     * Viva") ou uma frase, e não um nome de bairro? Heurística de aviso.
     */
    public static function looksLikeReference(?string $bairro): bool
    {
        $chave = self::key($bairro);
        if ($chave === '') {
            return false;
        }

        foreach (self::PREFIXOS_REFERENCIA as $prefixo) {
            if (str_starts_with($chave, $prefixo)) {
                return true;
            }
        }

        return mb_strlen($chave, 'UTF-8') > 60;
    }
}
