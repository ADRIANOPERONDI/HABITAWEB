<?php

namespace App\Libraries\Geo;

/**
 * Converte um endereço em coordenadas.
 *
 * Sem ligação alguma com um provedor específico: o chamador não sabe (nem
 * precisa saber) se a implementação por trás é a Nominatim, um cache, ou um
 * dublê de teste que nunca toca em rede.
 */
interface GeocoderInterface
{
    /**
     * @param array{rua?:?string, numero?:?string, bairro?:?string, cidade?:?string, estado?:?string} $endereco
     *
     * @return array{lat:float, lng:float}|null null quando não encontrou ou a consulta falhou —
     *                                           a implementação deve ser fail-open, nunca lançar.
     */
    public function geocode(array $endereco): ?array;
}
