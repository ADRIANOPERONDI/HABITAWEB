<?php

namespace App\Libraries\Media;

/**
 * Download de imagens a partir de URL informada pelo parceiro.
 *
 * É o que permite sincronizar um catálogo inteiro sem o parceiro ter de fazer
 * N uploads multipart: ele manda as URLs que já usa no site dele e o servidor
 * busca.
 *
 * Isso significa que uma URL controlada por terceiro vira uma requisição HTTP
 * SAINDO do nosso servidor — o vetor clássico de SSRF. As defesas aqui:
 *   - apenas http/https (sem file://, gopher://, dict://…);
 *   - o IP resolvido é checado contra faixas privadas/loopback/link-local,
 *     incluindo 169.254.169.254 (metadados de nuvem);
 *   - a checagem é refeita a cada redirect, com os redirects seguidos
 *     manualmente — seguir com CURLOPT_FOLLOWLOCATION permitiria burlar a
 *     validação com um 302 para um destino interno (DNS rebinding);
 *   - teto de tamanho aplicado durante o download, não só no fim;
 *   - o conteúdo é validado como imagem real depois de baixado.
 */
class RemoteImageFetcher
{
    public const MAX_BYTES     = 10485760; // 10 MB
    public const MAX_REDIRECTS = 3;
    public const TIMEOUT       = 15;

    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Baixa a imagem e devolve o caminho de um arquivo temporário.
     *
     * @return array{success: bool, path?: string, mime?: string, extension?: string, message?: string}
     */
    public function fetch(string $url): array
    {
        $url = trim($url);

        $validation = $this->validateUrl($url);
        if (! $validation['valid']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'habitaweb_img_');

        if ($tmpPath === false) {
            return ['success' => false, 'message' => 'Não foi possível criar arquivo temporário.'];
        }

        $downloaded = $this->download($url, $tmpPath);

        if (! $downloaded['success']) {
            @unlink($tmpPath);

            return $downloaded;
        }

        $inspection = $this->inspectImage($tmpPath);

        if (! $inspection['success']) {
            @unlink($tmpPath);

            return $inspection;
        }

        return [
            'success'   => true,
            'path'      => $tmpPath,
            'mime'      => $inspection['mime'],
            'extension' => $inspection['extension'],
        ];
    }

    /**
     * Valida esquema e destino da URL. Delegado ao UrlGuard, compartilhado com
     * a validação de target_url dos webhooks.
     *
     * @return array{valid: bool, message?: string}
     */
    public function validateUrl(string $url): array
    {
        return (new \App\Libraries\Http\UrlGuard())->validate($url);
    }

    /**
     * Baixa seguindo redirects manualmente, revalidando o destino a cada salto.
     *
     * @return array{success: bool, message?: string}
     */
    private function download(string $url, string $destination): array
    {
        $currentUrl = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $handle = fopen($destination, 'wb');

            if ($handle === false) {
                return ['success' => false, 'message' => 'Não foi possível gravar o arquivo temporário.'];
            }

            $bytes    = 0;
            $tooLarge = false;

            $ch = curl_init($currentUrl);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, // seguimos à mão para revalidar cada destino
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_USERAGENT      => 'Habitaweb-ImageFetcher/1.0',
                CURLOPT_HEADER         => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION  => static function ($ch, $chunk) use ($handle, &$bytes, &$tooLarge) {
                    $bytes += strlen($chunk);

                    if ($bytes > self::MAX_BYTES) {
                        $tooLarge = true;

                        return 0; // aborta o transfer
                    }

                    return fwrite($handle, $chunk);
                },
            ]);

            curl_exec($ch);

            $errno    = curl_errno($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            fclose($handle);

            if ($tooLarge) {
                return [
                    'success' => false,
                    'message' => 'A imagem excede o tamanho máximo de ' . (self::MAX_BYTES / 1048576) . ' MB.',
                ];
            }

            if ($errno !== 0) {
                return ['success' => false, 'message' => 'Falha ao baixar a imagem da URL informada.'];
            }

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                $check = $this->validateUrl($location);

                if (! $check['valid']) {
                    return ['success' => false, 'message' => $check['message']];
                }

                $currentUrl = $location;
                continue;
            }

            if ($status < 200 || $status >= 300) {
                return ['success' => false, 'message' => "A URL respondeu HTTP {$status}."];
            }

            if ($bytes === 0) {
                return ['success' => false, 'message' => 'A URL não retornou conteúdo.'];
            }

            return ['success' => true];
        }

        return ['success' => false, 'message' => 'Excesso de redirecionamentos na URL informada.'];
    }

    /**
     * Confirma que o que baixamos é mesmo uma imagem aceita e utilizável.
     *
     * @return array{success: bool, mime?: string, extension?: string, message?: string}
     */
    private function inspectImage(string $path): array
    {
        $mime = null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
        }

        if (! isset(self::ALLOWED_MIMES[$mime])) {
            return [
                'success' => false,
                'message' => 'A URL não aponta para uma imagem JPG, PNG ou WebP.',
            ];
        }

        $dimensions = @getimagesize($path);

        if ($dimensions === false) {
            return ['success' => false, 'message' => 'O conteúdo baixado não é uma imagem válida.'];
        }

        [$width, $height] = $dimensions;

        if ($width < 100 || $height < 100 || $width > 12000 || $height > 12000) {
            return [
                'success' => false,
                'message' => 'Dimensões de imagem inválidas (entre 100px e 12000px).',
            ];
        }

        return ['success' => true, 'mime' => $mime, 'extension' => self::ALLOWED_MIMES[$mime]];
    }
}
