<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Files\File;

class PropertyMediaController extends BaseController
{
    /**
     * Confirma que o imóvel pertence à conta do usuário logado.
     *
     * O grupo de rotas só aplicava o filtro admin_auth, que verifica login mas
     * não posse. Como estes três endpoints recebiam um id cru e nunca checavam
     * account_id, qualquer usuário autenticado de QUALQUER conta podia subir,
     * apagar ou trocar a capa das fotos de imóveis de outra conta (IDOR).
     * Admin\PropertyController::update/delete já faziam essa checagem.
     *
     * @return true|\CodeIgniter\HTTP\ResponseInterface true se autorizado
     */
    private function authorizeProperty($propertyId)
    {
        $user = auth()->user();

        if (! $user) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'error' => 'Não autenticado.']);
        }

        $property = model('App\Models\PropertyModel')->find((int) $propertyId);

        if (! $property) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'error' => 'Imóvel não encontrado.']);
        }

        // Superadmin/admin da plataforma acessam qualquer imóvel.
        if ($user->inGroup('superadmin', 'admin')) {
            return true;
        }

        if ((int) $property->account_id !== (int) ($user->account_id ?? 0)) {
            log_message('warning', sprintf(
                'IDOR attempt: user %d (account %s) tentou manipular mídia do imóvel %d (account %d)',
                $user->id,
                $user->account_id ?? 'null',
                $propertyId,
                $property->account_id
            ));

            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'error' => 'Acesso negado a este imóvel.']);
        }

        return true;
    }

    public function upload($propertyId)
    {
        $authorized = $this->authorizeProperty($propertyId);
        if ($authorized !== true) {
            return $authorized;
        }

        $file = $this->request->getFile('file');

        if (! $file || ! $file->isValid()) {
            return $this->response->setJSON(['error' => 'Arquivo inválido.']);
        }

        // Limite de fotos por imóvel do plano (plans.limite_fotos_por_imovel).
        $property   = model('App\Models\PropertyModel')->find((int) $propertyId);
        $photoLimit = (new \App\Services\PropertyService())->checkPhotoLimit(
            (int) $property->account_id,
            (int) $propertyId
        );

        if (! $photoLimit['allowed']) {
            return $this->response->setStatusCode(409)->setJSON(['error' => $photoLimit['message']]);
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
        $targetPath = 'uploads/properties/' . $newName;

        // SECURITY: Remove EXIF metadata to prevent privacy leaks (GPS, camera info, ISO, timestamps).
        // No arquivo temporário, ANTES de entregar ao storage — com backend
        // remoto (S3) não existe caminho absoluto final para pós-processar.
        // Lógica compartilhada com o caminho da API (PropertyService::addMedia).
        \App\Libraries\Media\ImageSanitizer::stripMetadata($file->getTempName());

        // Variantes (thumbnails card/gallery) antes do put() — o put() consome
        // o arquivo temporário de origem.
        (new \App\Libraries\Media\ImageVariantGenerator())->generate($file->getTempName(), $targetPath);

        // Grava via storage abstrato (disco público) — troca de backend
        // (S3/NFS) acontece só em Config\Services, não aqui.
        $storage = service('publicStorage');
        $relativePath = $storage->put($targetPath, $file->getTempName());

        // Instancia o Model
        $mediaModel = model('App\Models\PropertyMediaModel');

        // Verifica se já existe imagem principal
        $hasMain = $mediaModel->where('property_id', $propertyId)
                              ->where('principal', true)
                              ->countAllResults() > 0;

        $mediaId = $mediaModel->insert([
            'property_id' => $propertyId,
            'url'         => $relativePath,
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
            'url' => $storage->getPublicUrl($relativePath),
            'is_main' => $isMainReally
        ]);
    }

    public function delete($id)
    {
        $mediaModel = model('App\Models\PropertyMediaModel');
        $media = $mediaModel->find($id);

        if ($media) {
            $authorized = $this->authorizeProperty($media->property_id);
            if ($authorized !== true) {
                return $authorized;
            }

            // Remove arquivo físico via storage abstrato (original + variantes)
            service('publicStorage')->delete($media->url);
            (new \App\Libraries\Media\ImageVariantGenerator())->deleteVariants($media->url);
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

        $authorized = $this->authorizeProperty($propertyId);
        if ($authorized !== true) {
            return $authorized;
        }

        // Set new main using atomic model method
        $mediaModel->setMainMedia($propertyId, $id);

        // Recalcula score (o fato de ter principal pode influenciar)
        $rankingService = service('rankingService');
        $rankingService->updateScore($propertyId);

        return $this->response->setJSON(['success' => true]);
    }
}
