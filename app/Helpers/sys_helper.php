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

if (!function_exists('audit_log')) {
    /**
     * Registra uma ação crítica na tabela audit_logs (quem, o quê, quando, de onde).
     *
     * Nunca lança exceção: se a auditoria falhar, apenas loga em arquivo e deixa a
     * ação principal seguir. Uso:
     *   audit_log('user.role_changed', [
     *       'account_id' => 5, 'entity_type' => 'user', 'entity_id' => 17,
     *       'metadata' => ['from' => 'corretor', 'to' => 'admin'],
     *   ]);
     */
    function audit_log(string $action, array $context = []): void
    {
        try {
            $request = service('request');

            // Ator: usuário logado no painel, ou o auth_user_id injetado pelo filtro de API.
            $actorId = $context['actor_user_id'] ?? null;
            if ($actorId === null && function_exists('auth') && auth()->loggedIn()) {
                $actorId = auth()->id();
            }
            if ($actorId === null && isset($request->auth_user_id)) {
                $actorId = $request->auth_user_id;
            }

            $ip = method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null;
            $ua = method_exists($request, 'getUserAgent') ? substr((string) $request->getUserAgent(), 0, 255) : null;

            // Enquanto a migration da tabela não tiver sido rodada, vira no-op silencioso.
            // Sem esta checagem, o INSERT dispara um erro do Postgres que polui o log com
            // stack trace a cada ação e pode abortar a transação corrente da requisição.
            static $tableExists = null;
            if ($tableExists === null) {
                $tableExists = db_connect()->tableExists('audit_logs');
            }
            if (! $tableExists) {
                return;
            }

            model(\App\Models\AuditLogModel::class)->insert([
                'actor_user_id' => $actorId,
                'account_id'    => $context['account_id'] ?? null,
                'action'        => $action,
                'entity_type'   => $context['entity_type'] ?? null,
                'entity_id'     => isset($context['entity_id']) ? (string) $context['entity_id'] : null,
                'ip_address'    => $ip,
                'user_agent'    => $ua,
                'metadata'      => isset($context['metadata']) ? json_encode($context['metadata']) : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[audit_log] Falha ao registrar auditoria: ' . $e->getMessage());
        }
    }
}

if (!function_exists('clean_html')) {
    /**
     * Sanitiza HTML vindo de campos rich-text (ex.: descrição de imóvel do CKEditor)
     * antes de exibir, removendo scripts, handlers de evento (onerror, onclick...) e
     * qualquer tag/atributo perigoso, preservando apenas formatação segura.
     *
     * Usa HTMLPurifier quando a lib estiver instalada (ezyang/htmlpurifier). Enquanto
     * a dependência não estiver disponível (antes de `composer install`), faz fallback
     * seguro para esc() — nunca deixa HTML não sanitizado passar. Ou seja: seguro em
     * TODOS os estágios; a única diferença é que o fallback perde a formatação até a
     * lib ser instalada em homologação.
     *
     * @param string|null $html
     * @return string
     */
    function clean_html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        if (class_exists(\HTMLPurifier::class)) {
            static $purifier = null;
            if ($purifier === null) {
                $config = \HTMLPurifier_Config::createDefault();
                // Cache de serialização do HTMLPurifier dentro de writable/
                $cacheDir = WRITEPATH . 'cache/htmlpurifier';
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                $config->set('Cache.SerializerPath', $cacheDir);
                // Allowlist de tags/atributos seguros para descrições de imóveis.
                $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,ul,ol,li,h3,h4,h5,blockquote,span[style],a[href|title|target|rel]');
                $config->set('CSS.AllowedProperties', 'text-align,font-weight,font-style,text-decoration');
                $config->set('Attr.AllowedFrameTargets', ['_blank']);
                $config->set('HTML.TargetBlank', true); // força rel=noopener em links _blank
                $purifier = new \HTMLPurifier($config);
            }
            return $purifier->purify($html);
        }

        // Fallback seguro enquanto a lib não estiver instalada.
        return esc($html);
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

if (!function_exists('media_url')) {
    /**
     * URL pública de um arquivo ENVIADO (logo de conta/parceiro, imagem de
     * settings, mídia de imóvel), resolvida pelo storage abstrato em vez de
     * base_url() fixo — com driver local o resultado é idêntico a base_url();
     * com S3 (duas vias) a URL aponta para onde o arquivo realmente está
     * (bucket/CDN ou disco local do fallback).
     *
     * Nunca usar para assets do tema (assets/...): esses são estáticos do
     * repositório e continuam com base_url() direto.
     */
    function media_url(?string $path): string
    {
        if (empty($path)) {
            return '';
        }

        // URLs absolutas externas (ou já absolutas) passam intactas.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $path = ltrim($path, '/');

        try {
            return service('publicStorage')->getPublicUrl($path) ?? base_url($path);
        } catch (\Throwable $e) {
            // Caminho malformado vindo do banco não pode derrubar a view.
            return base_url($path);
        }
    }
}

if (!function_exists('media_variant_url')) {
    /**
     * URL da variante redimensionada de uma mídia de imóvel ('card' ~480px,
     * 'gallery' ~1280px — ver App\Libraries\Media\ImageVariantGenerator), com
     * fallback gracioso para o ORIGINAL quando a variante não existe (imagens
     * legadas anteriores ao gerador, origem menor que o alvo, ou falha de
     * geração). Assim as views sempre podem pedir a variante sem se preocupar.
     *
     * Nota de escala: exists() no LocalStorage é um stat local barato; no
     * backend S3 de duas vias o FallbackStorage cacheia a localização, então
     * o custo de rede é pago uma vez por arquivo, não por render.
     */
    function media_variant_url(?string $url, string $variant = 'card'): string
    {
        if (empty($url)) {
            return base_url('assets/img/placeholder-house.png');
        }

        // URLs absolutas externas (ou já absolutas) passam intactas.
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $variantPath = \App\Libraries\Media\ImageVariantGenerator::variantPath(ltrim($url, '/'), $variant);

        if (service('publicStorage')->exists($variantPath)) {
            return service('publicStorage')->getPublicUrl($variantPath);
        }

        return media_url($url);
    }
}
