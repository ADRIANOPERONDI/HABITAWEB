<?php

namespace App\Libraries\Media;

/**
 * Remoção de metadados EXIF das imagens antes de publicá-las.
 *
 * Fotos de imóvel tiradas com celular carregam GPS, modelo do aparelho e
 * timestamp. Publicar isso vaza a localização exata de um imóvel que o
 * anunciante pode ter escolhido não divulgar, além do metadado do fotógrafo.
 *
 * Esta lógica existia apenas no upload do painel admin
 * (Admin\PropertyMediaController::removeExifData), enquanto o caminho da API
 * (PropertyService::addMedia) publicava tudo verbatim. Como parceiro nenhum vai
 * limpar EXIF por conta própria, foi extraída para cá e passou a ser chamada
 * pelos DOIS caminhos.
 */
class ImageSanitizer
{
    /**
     * Reescreve a imagem no lugar, sem metadados.
     *
     * A operação é best-effort: se falhar, a imagem original continua válida e
     * o upload segue — logamos e não derrubamos a requisição do usuário.
     *
     * @param string $imagePath Caminho absoluto do arquivo a higienizar.
     */
    public static function stripMetadata(string $imagePath): bool
    {
        try {
            $imageInfo = @getimagesize($imagePath);

            if (! $imageInfo) {
                log_message('warning', "[ImageSanitizer] imagem inválida em {$imagePath}");

                return false;
            }

            switch ($imageInfo['mime']) {
                case 'image/jpeg':
                    // Imagick remove todos os perfis de uma vez e sem recomprimir
                    // a imagem inteira; é o caminho preferido quando disponível.
                    if (extension_loaded('imagick')) {
                        $image = new \Imagick($imagePath);
                        $image->stripImage();
                        $image->writeImage($imagePath);
                        $image->destroy();

                        return true;
                    }

                    $image = @imagecreatefromjpeg($imagePath);
                    if ($image === false) {
                        log_message('error', "[ImageSanitizer] GD não abriu o JPEG {$imagePath}");

                        return false;
                    }
                    imagejpeg($image, $imagePath, 90);
                    imagedestroy($image);

                    return true;

                case 'image/png':
                    $image = @imagecreatefrompng($imagePath);
                    if ($image === false) {
                        log_message('error', "[ImageSanitizer] GD não abriu o PNG {$imagePath}");

                        return false;
                    }
                    // PNG com transparência precisa destes dois antes do save,
                    // senão o alpha vira preto na reescrita.
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagepng($image, $imagePath, 9);
                    imagedestroy($image);

                    return true;

                case 'image/webp':
                    $image = @imagecreatefromwebp($imagePath);
                    if ($image === false) {
                        log_message('error', "[ImageSanitizer] GD não abriu o WebP {$imagePath}");

                        return false;
                    }
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagewebp($image, $imagePath, 90);
                    imagedestroy($image);

                    return true;
            }

            return false;
        } catch (\Throwable $e) {
            log_message('error', '[ImageSanitizer] falha ao remover metadados: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * True se a imagem ainda tiver EXIF. Usado em teste para provar que o strip
     * funcionou de verdade.
     */
    public static function hasExif(string $imagePath): bool
    {
        if (! function_exists('exif_read_data')) {
            return false;
        }

        $data = @exif_read_data($imagePath);

        if (! is_array($data)) {
            return false;
        }

        // exif_read_data devolve chaves sintéticas (FileName, FileSize...) mesmo
        // sem EXIF real; só interessam os blocos de metadado de câmera/GPS.
        foreach (['GPS', 'IFD0', 'EXIF', 'MAKE', 'MODEL', 'GPSLatitude', 'Make', 'Model'] as $key) {
            if (isset($data[$key])) {
                return true;
            }
        }

        return false;
    }
}
