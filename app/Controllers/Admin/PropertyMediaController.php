<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Files\File;

class PropertyMediaController extends BaseController
{
    public function upload($propertyId)
    {
        $file = $this->request->getFile('file');
        
        if (! $file || ! $file->isValid()) {
            return $this->response->setJSON(['error' => 'Arquivo inválido.']);
        }

        // FIXED: Enhanced validation to prevent malicious uploads
        $validationRule = [
            'file' => [
                'label' => 'Image File',
                'rules' => [
                    'uploaded[file]',
                    'is_image[file]',  
                    'mime_in[file,image/jpg,image/jpeg,image/png,image/webp]',
                    'max_size[file,5120]', // 5MB
                ],
            ],
        ];
        
        if (! $this->validate($validationRule)) {
             return $this->response->setJSON(['error' => $this->validator->getErrors()]);
        }
        
        // Additional security: Verify image dimensions (prevent bombs)
        $imageInfo = @getimagesize($file->getTempName());
        if (!$imageInfo) {
            return $this->response->setJSON(['error' => 'Arquivo não é imagem válida.']);
        }
        
        [$width, $height] = $imageInfo;
        if ($width < 200 || $height < 200) {
            return $this->response->setJSON(['error' => 'Imagem muito pequena (mín 200x200).']);
        }
        if ($width > 10000 || $height > 10000) {
            return $this->response->setJSON(['error' => 'Imagem muito grande (máx 10000x10000).']);
        }
        
        // Verify actual MIME type (prevent executable files disguised as images)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file->getTempName());
        finfo_close($finfo);
        
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'])) {
            log_message('warning', "Suspicious upload: {$mimeType} from IP {$this->request->getIPAddress()}");
            return $this->response->setJSON(['error' => 'Tipo de arquivo não permitido.']);
        }

        // Gera nome único
        $newName = $file->getRandomName();
        
        // Move para public/uploads/properties
        // Nota: Em produção usaríamos writable e serviríamos via controller ou symlink/CDN.
        // Para simplificar agora: public path.
        $path = FCPATH . 'uploads/properties/';
        
        $file->move($path, $newName);

        // SECURITY: Remove EXIF metadata to prevent privacy leaks (GPS, camera info, ISO, timestamps)
        $fullPath = $path . $newName;
        $this->removeExifData($fullPath);

        // Instancia o Model
        $mediaModel = model('App\Models\PropertyMediaModel');

        // Verifica se já existe imagem principal
        $hasMain = $mediaModel->where('property_id', $propertyId)
                              ->where('principal', true)
                              ->countAllResults() > 0;

        $mediaId = $mediaModel->insert([
            'property_id' => $propertyId,
            'url'         => 'uploads/properties/' . $newName,
            'tipo'        => 'IMAGE',
            'ordem'       => 0, // TODO: Implementar ordenação
            'principal'   => ! $hasMain, // Primeira imagem vira principal
            'created_at'  => date('Y-m-d H:i:s')
        ]);

        // Race Condition Fix: Execução Atômica (PostgreSQL Compatible)
        // Garantir que apenas UMA imagem seja principal por property_id
        $mediaModel->sanitizeMain($propertyId);

        // Recalcula score
        $rankingService = service('rankingService');
        $rankingService->updateScore($propertyId);

        // Verificação final do status (DB Truth) para retornar ao frontend
        // Isso previne que a UI mostre "Capa" para imagens que foram rebaixadas pela lógica acima
        $freshMedia = $mediaModel->find($mediaId);
        $isMainReally = $freshMedia ? (bool) $freshMedia->principal : false;

        return $this->response->setJSON([
            'success' => true,
            'id' => $mediaId,
            'url' => base_url('uploads/properties/' . $newName),
            'is_main' => $isMainReally
        ]);
    }

    public function delete($id)
    {
        $mediaModel = model('App\Models\PropertyMediaModel');
        $media = $mediaModel->find($id);

        if ($media) {
            // Remove arquivo físico
            $filePath = FCPATH . $media->url; // CORRIGIDO: era media_url, correto é url
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            // Remove do banco
            $propertyId = $media->property_id;
            $mediaModel->delete($id);

            // Recalcula score
            $rankingService = service('rankingService');
            $rankingService->updateScore($propertyId);

            return $this->response->setJSON(['success' => true]);
        }
        
        return $this->response->setJSON(['success' => false, 'error' => 'Mídia não encontrada']);
    }

    public function setMain($id)
    {
        $mediaModel = model('App\Models\PropertyMediaModel');
        $media = $mediaModel->find($id);

        if (! $media) {
            return $this->response->setJSON(['success' => false, 'error' => 'Mídia não encontrada']);
        }

        $propertyId = $media->property_id;

        // Set new main using atomic model method
        $mediaModel->setMainMedia($propertyId, $id);

        // Recalcula score (o fato de ter principal pode influenciar)
        $rankingService = service('rankingService');
        $rankingService->updateScore($propertyId);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Remove EXIF metadata from image to prevent privacy leaks
     * SECURITY: Prevents exposure of GPS location, camera model, ISO, timestamps, etc.
     *
     * @param string $imagePath Full path to the image file
     * @return bool Success/Failure
     */
    private function removeExifData(string $imagePath): bool
    {
        try {
            // Get image info using getimagesize (includes MIME type)
            $imageInfo = @getimagesize($imagePath);
            if (!$imageInfo) {
                log_message('warning', "EXIF removal: Invalid image at {$imagePath}");
                return false;
            }

            $mimeType = $imageInfo['mime'];

            // Handle JPEG images (most likely to have EXIF)
            if ($mimeType === 'image/jpeg') {
                if (extension_loaded('imagick')) {
                    // Preferred: Use ImageMagick if available (strips all metadata)
                    $image = new \Imagick($imagePath);
                    $image->stripImage(); // Remove all profiles/metadata
                    $image->writeImage($imagePath);
                    $image->destroy();
                    return true;
                } else {
                    // Fallback: Use GD library to recompress without metadata
                    $image = @imagecreatefromjpeg($imagePath);
                    if ($image === false) {
                        log_message('error', "EXIF removal: GD failed to load JPEG {$imagePath}");
                        return false;
                    }
                    imagejpeg($image, $imagePath, 90); // Recompress at 90% quality
                    imagedestroy($image);
                    return true;
                }
            }

            // Handle PNG images
            elseif ($mimeType === 'image/png') {
                $image = @imagecreatefrompng($imagePath);
                if ($image === false) {
                    log_message('error', "EXIF removal: GD failed to load PNG {$imagePath}");
                    return false;
                }
                // Save without metadata (PNG from GD doesn't preserve EXIF)
                imagepng($image, $imagePath, 9);
                imagedestroy($image);
                return true;
            }

            // Handle WebP images
            elseif ($mimeType === 'image/webp') {
                $image = @imagecreatefromwebp($imagePath);
                if ($image === false) {
                    log_message('error', "EXIF removal: GD failed to load WebP {$imagePath}");
                    return false;
                }
                imagewebp($image, $imagePath, 90);
                imagedestroy($image);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            log_message('error', "EXIF removal error: " . $e->getMessage());
            return false;
        }
    }
}

