<?php

namespace App\Libraries\Media;

/**
 * Gera variantes redimensionadas (thumbnails) de uma imagem de imóvel no
 * momento do upload, gravando-as no mesmo storage do original com nome
 * determinístico: abc123.jpg -> abc123_card.jpg / abc123_gallery.jpg.
 *
 * Decisões de design:
 * - SEM mudança de schema: property_media.url continua apontando o original;
 *   as views resolvem a variante via media_variant_url() (sys_helper), que cai
 *   graciosamente no original quando a variante não existe — imagens legadas
 *   funcionam sem backfill obrigatório (backfill opcional: spark media:variants).
 * - O redimensionamento acontece numa CÓPIA do arquivo temporário, ANTES do
 *   put() do original — LocalStorage::put() consome (unlink) o source, e com
 *   backend remoto (S3) não existe caminho absoluto final pra pós-processar.
 * - O handler GD do CI4 decide o formato pelo tipo detectado no load
 *   (getimagesize), não pela extensão — tmp sem extensão funciona (mesmo
 *   racional já verificado em ProfileController::moveAndOptimizeImage).
 * - Falha na geração de variante NUNCA derruba o upload: loga warning e segue;
 *   o helper simplesmente servirá o original.
 */
class ImageVariantGenerator
{
    /**
     * variante => [largura máxima em px, qualidade JPEG/WebP]
     * - card: cards de listagem (home, busca, favoritos) — hoje renderizados
     *   a ~200-420px de largura; 480px cobre retina sem exagero.
     * - gallery: imagem principal da página de detalhes.
     */
    public const VARIANTS = [
        'card'    => [480, 78],
        'gallery' => [1280, 82],
    ];

    /**
     * Caminho da variante a partir do caminho do original:
     * uploads/properties/7/abc.jpg + 'card' -> uploads/properties/7/abc_card.jpg
     */
    public static function variantPath(string $relativePath, string $variant): string
    {
        $dot = strrpos($relativePath, '.');
        if ($dot === false) {
            return $relativePath . '_' . $variant;
        }

        return substr($relativePath, 0, $dot) . '_' . $variant . substr($relativePath, $dot);
    }

    /**
     * Gera e grava todas as variantes aplicáveis.
     *
     * @param string $tmpFile            Arquivo local já VALIDADO (MIME real,
     *                                   dimensões) — a validação de segurança
     *                                   permanece no chamador, antes daqui.
     * @param string $targetRelativePath Caminho relativo que o ORIGINAL terá no
     *                                   storage (base para o nome das variantes).
     */
    public function generate(string $tmpFile, string $targetRelativePath): void
    {
        $info = @getimagesize($tmpFile);
        if ($info === false) {
            return;
        }
        [$sourceWidth] = $info;

        foreach (self::VARIANTS as $variant => [$maxWidth, $quality]) {
            // Sem upscale: origem menor/igual ao alvo não ganha variante — o
            // helper cai no original, que já é pequeno o suficiente.
            if ($sourceWidth <= $maxWidth) {
                continue;
            }

            $variantTmp = tempnam(sys_get_temp_dir(), 'imgvar_');
            if ($variantTmp === false || ! @copy($tmpFile, $variantTmp)) {
                log_message('warning', "[ImageVariantGenerator] Falha ao preparar tmp da variante {$variant} de {$targetRelativePath}");
                continue;
            }

            try {
                \Config\Services::image('gd')
                    ->withFile($variantTmp)
                    ->reorient(true)
                    ->resize($maxWidth, $maxWidth * 10, true, 'width')
                    ->save($variantTmp, $quality);

                service('publicStorage')->put(
                    self::variantPath($targetRelativePath, $variant),
                    $variantTmp // consumido pelo put()
                );
            } catch (\Throwable $e) {
                @unlink($variantTmp);
                log_message('warning', "[ImageVariantGenerator] Falha ao gerar variante {$variant} de {$targetRelativePath}: " . $e->getMessage());
            }
        }
    }

    /**
     * Remove as variantes associadas a um original (para o delete de mídia).
     * Deletar variante inexistente é no-op (delete() retorna false sem erro).
     */
    public function deleteVariants(string $relativePath): void
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            service('publicStorage')->delete(self::variantPath($relativePath, $variant));
        }
    }
}
