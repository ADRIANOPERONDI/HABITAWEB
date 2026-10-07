<?php

namespace App\Libraries\Search;

/**
 * Normaliza o polígono que chega pela query string (`?polygon=`) antes de
 * virar filtro de busca.
 *
 * O formato é o que o Leaflet.draw produz na tela de busca: JSON com uma
 * lista de pares `[lng, lat]`. Ele chega por dois caminhos — o fetch da
 * própria tela (`api/imoveis/mapa`) e o deep link vindo do mapa da home
 * (`imoveis/mapa?polygon=`) — e nos dois o valor é devolvido ao cliente
 * (atributo `value` do input oculto, URL sincronizada pelo JS, métrica de
 * busca). Por isso o que não for um polígono válido vira `null` aqui, nunca
 * eco de lixo.
 *
 * `PropertyService::applySearchFilters()` continua fazendo a própria leitura
 * (json_decode + cast para float): esta classe é a borda da aplicação, o
 * service é a última linha de defesa.
 */
final class PolygonFilter
{
    public const MIN_VERTICES = 3;

    public const MAX_VERTICES = 200;

    /**
     * Tamanho máximo da string bruta. 200 vértices com 15 casas decimais
     * ocupam ~8 KB; o dobro dá folga sem aceitar payload arbitrário.
     */
    public const MAX_INPUT_LENGTH = 16000;

    /**
     * Devolve o polígono como JSON compacto `[[lng,lat],...]`, ou null quando
     * a entrada não é um polígono utilizável.
     */
    public static function normalize(mixed $raw): ?string
    {
        $points = self::parse($raw);

        return $points === null ? null : json_encode($points);
    }

    /**
     * @return list<array{0: float, 1: float}>|null pares [lng, lat], ou null se inválido
     */
    public static function parse(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > self::MAX_INPUT_LENGTH) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $count = count($decoded);
        if ($count < self::MIN_VERTICES || $count > self::MAX_VERTICES) {
            return null;
        }

        $points = [];
        foreach ($decoded as $pair) {
            // Um terceiro elemento (altitude, como no GeoJSON) é ignorado;
            // menos de dois não é coordenada.
            if (! is_array($pair) || ! array_key_exists(0, $pair) || ! array_key_exists(1, $pair)) {
                return null;
            }

            $lng = self::coordinate($pair[0], 180.0);
            $lat = self::coordinate($pair[1], 90.0);
            if ($lng === null || $lat === null) {
                return null;
            }

            $points[] = [$lng, $lat];
        }

        return $points;
    }

    private static function coordinate(mixed $value, float $limit): ?float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (float) $value;
        if (! is_finite($value) || abs($value) > $limit) {
            return null;
        }

        return $value;
    }
}
