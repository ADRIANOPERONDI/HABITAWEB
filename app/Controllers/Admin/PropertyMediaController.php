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

        // Validação básica
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

        // Gera nome único
        $newName = $file->getRandomName();
        
        // Move para public/uploads/properties
        // Nota: Em produção usaríamos writable e serviríamos via controller ou symlink/CDN.
        // Para simplificar agora: public path.
        $path = FCPATH . 'uploads/properties/';
        
        $file->move($path, $newName);

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
}
