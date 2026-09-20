<?php

namespace App\Libraries\Text;

/**
 * Forma canônica do nome de uma cidade.
 *
 * Existe porque `properties.cidade` é texto livre alimentado por caminhos que
 * nunca combinaram entre si: o sync do Simob grava "SÃO MIGUEL DO OESTE", o
 * ViaCEP do formulário admin grava "São Miguel do Oeste". Como
 * `SELECT DISTINCT cidade` é sensível a caixa no Postgres, a mesma cidade
 * aparecia duas vezes no filtro da busca — e escolher uma das opções escondia
 * os imóveis da outra.
 *
 * Title Case com preposições em minúscula: "São Miguel do Oeste", não
 * "São Miguel Do Oeste", que é o que mb_convert_case(MB_CASE_TITLE) devolve
 * sozinho.
 */
final class CityName
{
    /**
     * Palavras que ficam em minúscula quando NÃO são a primeira. A lista é
     * curta de propósito: só o que aparece em topônimo brasileiro.
     */
    private const MINUSCULAS = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'a', 'o', 'à'];

    public static function normalize(?string $cidade): ?string
    {
        if ($cidade === null) {
            return null;
        }

        // Espaço repetido no meio vira um só — "SÃO  MIGUEL" e "SÃO MIGUEL"
        // não podem virar duas cidades.
        $limpa = trim(preg_replace('/\s+/u', ' ', $cidade) ?? '');

        if ($limpa === '') {
            return '';
        }

        $palavras = explode(' ', mb_strtolower($limpa, 'UTF-8'));

        foreach ($palavras as $i => $palavra) {
            if ($i > 0 && in_array($palavra, self::MINUSCULAS, true)) {
                continue;
            }

            $palavras[$i] = self::capitalizar($palavra);
        }

        return implode(' ', $palavras);
    }

    /**
     * Capitaliza respeitando hífen e apóstrofo, que separam partes de um mesmo
     * topônimo: "santa bárbara d'oeste" -> "Santa Bárbara d'Oeste",
     * "mogi-guaçu" -> "Mogi-Guaçu". O trecho ANTES do apóstrofo fica minúsculo
     * quando é só a partícula ("d'", "l'"), que é como o IBGE escreve.
     */
    private static function capitalizar(string $palavra): string
    {
        foreach (['-', "'", '’'] as $separador) {
            if (! str_contains($palavra, $separador)) {
                continue;
            }

            $partes = explode($separador, $palavra);

            foreach ($partes as $i => $parte) {
                $particula = $separador !== '-' && $i === 0 && mb_strlen($parte, 'UTF-8') === 1;

                $partes[$i] = $particula ? $parte : self::capitalizar($parte);
            }

            return implode($separador, $partes);
        }

        if ($palavra === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($palavra, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($palavra, 1, null, 'UTF-8');
    }
}
