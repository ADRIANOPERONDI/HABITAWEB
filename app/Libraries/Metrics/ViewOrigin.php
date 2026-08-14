<?php

namespace App\Libraries\Metrics;

/**
 * Classificação simples de origem de visualização a partir do Referer —
 * não é rastreamento de UTM, é só o suficiente para separar
 * `property_view_source_daily` em quatro baldes legíveis no painel.
 */
class ViewOrigin
{
    private const BUSCADORES = ['google.', 'bing.', 'yahoo.', 'duckduckgo.'];
    private const REDES      = ['facebook.', 'instagram.', 'whatsapp', 'wa.me', 'linkedin.', 'tiktok.', 't.co', 'x.com'];

    public static function classify(?string $referrer): string
    {
        $referrer = mb_strtolower(trim((string) $referrer));

        if ($referrer === '') {
            return 'DIRETO';
        }

        foreach (self::REDES as $dominio) {
            if (str_contains($referrer, $dominio)) {
                return 'REDES_SOCIAIS';
            }
        }

        foreach (self::BUSCADORES as $dominio) {
            if (str_contains($referrer, $dominio)) {
                return 'BUSCA';
            }
        }

        return 'OUTRO';
    }
}
