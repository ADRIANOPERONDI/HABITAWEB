<?php

namespace App\Libraries\Geo;

/**
 * Geocoder que nunca encontra nada — nunca faz rede.
 *
 * Default seguro para qualquer chamador que não injetar um geocoder de
 * verdade, e o dublê usado em toda a suíte automatizada (nenhum teste pode
 * depender de bater no Nominatim de verdade).
 */
class NullGeocoder implements GeocoderInterface
{
    public function geocode(array $endereco): ?array
    {
        return null;
    }
}
