<?php

namespace Tests\Support\Integrations;

use App\Libraries\Geo\GeocoderInterface;

/** Geocoder dublê: nunca toca em rede, devolve uma coordenada fixa ou nada. */
class FakeGeocoder implements GeocoderInterface
{
    public int $calls = 0;

    public function __construct(private ?array $resultado = ['lat' => -27.1, 'lng' => -52.6])
    {
    }

    public function geocode(array $endereco): ?array
    {
        $this->calls++;

        return $this->resultado;
    }
}
