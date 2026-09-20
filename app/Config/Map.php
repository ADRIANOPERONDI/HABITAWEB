<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Fonte dos tiles (as "fotos" do mapa) do Leaflet.
 *
 * Isto existe porque o portal apontava direto para o servidor de tiles do
 * OpenStreetMap, mantido por doação. A política de uso deles não permite
 * aplicação comercial, e o domínio acabou bloqueado: o servidor passou a
 * responder com o cabeçalho `x-blocked` e um quadro "Access blocked" no lugar
 * de cada tile — o mapa inteiro do site virou um mosaico de avisos.
 *
 * O default abaixo continua sendo o OSM só pra que um ambiente sem .env
 * configurado não fique com o mapa em branco. Ele NÃO serve para produção:
 * defina MAP_TILE_URL com um provedor contratado (MapTiler, Mapbox, Carto,
 * ou tiles próprios) antes de publicar.
 */
class Map extends BaseConfig
{
    /**
     * Template de URL do tile, no formato que o Leaflet entende
     * ({z}/{x}/{y}, e {r} para telas retina quando o provedor suporta).
     */
    public string $tileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    /**
     * Crédito exibido no canto do mapa. Praticamente todo provedor exige o
     * dele por contrato — trocar a URL sem trocar isto viola a licença.
     */
    public string $tileAttribution = '&copy; OpenStreetMap contributors';

    /** Zoom máximo que o provedor entrega. */
    public int $tileMaxZoom = 19;

    /**
     * Lado do tile em pixels, como o provedor entrega.
     *
     * O Leaflet assume 256 e encaixa a imagem nesse espaço: com um provedor
     * que serve 512 (o caso do ArcGIS Static Basemap Tiles), cada tile ocupa
     * metade do espaço que deveria e o mapa mostra o DOBRO da área no mesmo
     * nível de zoom. Não quebra — fica silenciosamente na escala errada.
     */
    public int $tileSize = 256;

    /**
     * Compensação de zoom. Um provedor de 512 px precisa de -1: o Leaflet pede
     * um nível a mais para o detalhe bater com a escala. Sem isso, `tileSize`
     * sozinho corrige o tamanho mas não o nível de detalhe.
     */
    public int $tileZoomOffset = 0;

    public function __construct()
    {
        parent::__construct();

        $this->tileUrl         = $this->doEnv('MAP_TILE_URL', $this->tileUrl);
        $this->tileAttribution = $this->doEnv('MAP_TILE_ATTRIBUTION', $this->tileAttribution);
        $this->tileMaxZoom     = (int) $this->doEnv('MAP_TILE_MAX_ZOOM', (string) $this->tileMaxZoom);
        $this->tileSize        = (int) $this->doEnv('MAP_TILE_SIZE', (string) $this->tileSize);
        $this->tileZoomOffset  = (int) $this->doEnv('MAP_TILE_ZOOM_OFFSET', (string) $this->tileZoomOffset);
    }

    /**
     * env() devolve string VAZIA para uma chave declarada sem valor no .env
     * ("MAP_TILE_URL =") — não o default. Sem esta guarda, quem copiasse o
     * env.example e não preenchesse ficava com o mapa em branco em vez de
     * cair no default.
     */
    private function doEnv(string $chave, string $default): string
    {
        $valor = env($chave);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : $default;
    }
}
