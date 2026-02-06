<?php

use App\Models\SettingModel;
use CodeIgniter\Config\Factories;

if (!function_exists('app_setting')) {
    /**
     * Recupera uma configuração do sistema.
     * 
     * @param string $key Chave da configuração (ex: 'site.name')
     * @param mixed $default Valor padrão caso não exista
     * @return mixed
     */
    function app_setting(string $key, $default = null)
    {
        // Cache em memória static (para a mesma requisição)
        static $settingsCache = null;

        if ($settingsCache === null) {
            // Tenta buscar do cache persistente (File/Redis)
            // Chave global invalidada apenas quando salva configurações
            $settingsCache = cache()->get('app_settings_global');

            if ($settingsCache === null) {
                // Se não tem no cache, busca no banco
                $model = Factories::models(SettingModel::class);
                $all = $model->findAll();
                
                $settingsCache = [];
                foreach ($all as $s) {
                    $settingsCache[$s->key] = $s->value;
                }

                // Salva no cache persistente por 24h (ou até ser limpo pelo controller)
                cache()->save('app_settings_global', $settingsCache, 86400);
            }
        }

        return $settingsCache[$key] ?? $default;
    }
}

if (!function_exists('hexToRgb')) {
    /**
     * Converte hexadecimal em string RGB (r, g, b)
     */
    function hexToRgb($hex) {
        $hex = str_replace("#", "", $hex);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
        return "$r, $g, $b";
    }
}
