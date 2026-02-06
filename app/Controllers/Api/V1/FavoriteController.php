<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class FavoriteController extends ResourceController
{
    use ResponseTrait;

    public function toggle()
    {
        // Apenas usuários logados por enquanto
        if (!auth()->loggedIn()) {
            return $this->failUnauthorized('Você precisa estar logado para favoritar.');
        }

        $userId = auth()->id();
        $json = $this->request->getJSON();
        $propertyId = $json->property_id ?? null;

        if (!$propertyId) {
            return $this->fail('Property ID is required');
        }

        $model = model('App\Models\PropertyFavoriteModel');
        $existing = $model->where('user_id', $userId)
                          ->where('property_id', $propertyId)
                          ->first();

        if ($existing) {
            // Remove
            $model->delete($existing->id);
            return $this->respond(['status' => 'removed', 'message' => 'Removido dos favoritos']);
        } else {
            // Adiciona
            $model->insert([
                'user_id' => $userId,
                'property_id' => $propertyId
            ]);
            return $this->respondCreated(['status' => 'added', 'message' => 'Adicionado aos favoritos']);
        }
    }
}
