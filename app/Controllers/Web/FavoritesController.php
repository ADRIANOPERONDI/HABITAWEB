<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class FavoritesController extends BaseController
{
    use ResponseTrait;

    protected $favoriteModel;
    protected $propertyModel;

    public function __construct()
    {
        $this->favoriteModel = model('App\Models\PropertyFavoriteModel');
        $this->propertyModel = model('App\Models\PropertyModel');
    }

    /**
     * List user favorites
     */
    public function index()
    {
        $userId = service('auth')->id();
        
        if (!$userId) {
            // Se não logado, poderia ler de cookies, mas por enquanto redireciona ou mostra vazio
            return redirect()->to('login')->with('message', 'Faça login para ver seus favoritos.');
        }

        // Buscar IDs dos imóveis favoritos
        $favorites = $this->favoriteModel->where('user_id', $userId)->findAll();
        $propertyIds = array_column($favorites, 'property_id');
        
        $properties = [];
        if (!empty($propertyIds)) {
            $properties = $this->propertyModel->whereIn('id', $propertyIds)
                                            ->where('status', 'ativo') // Apenas ativos
                                            ->findAll();
        }

        return view('web/favorites/index', [
            'properties' => $properties
        ]);
    }

    /**
     * Toggle favorite status (AJAX)
     */
    public function toggle($propertyId)
    {
        if (!$this->request->isAJAX()) {
            return $this->failForbidden('Apenas requisições AJAX permitidas.');
        }

        $userId = service('auth')->id();
        
        if (!$userId) {
            return $this->failUnauthorized('Faça login para favoritar imóveis.');
        }

        $exists = $this->favoriteModel->where('user_id', $userId)
                                      ->where('property_id', $propertyId)
                                      ->first();

        try {
            if ($exists) {
                // Remover
                $this->favoriteModel->delete($exists->id);
                return $this->respond(['success' => true, 'action' => 'removed', 'message' => 'Imóvel removido dos favoritos.']);
            } else {
                // Adicionar
                $this->favoriteModel->insert([
                    'user_id' => $userId,
                    'property_id' => $propertyId
                ]);
                return $this->respond(['success' => true, 'action' => 'added', 'message' => 'Imóvel adicionado aos favoritos!']);
            }
        } catch (\Exception $e) {
            return $this->failServerError('Erro ao atualizar favoritos: ' . $e->getMessage());
        }
    }
}
