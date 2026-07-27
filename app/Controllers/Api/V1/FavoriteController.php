<?php

namespace App\Controllers\Api\V1;

/**
 * Favoritar/desfavoritar um imóvel.
 *
 * Montado em duas rotas com credenciais diferentes:
 *   - POST /favoritos/toggle   (site público, sessão + CSRF)
 *   - POST /api/v1/favorites/toggle (API, Bearer)
 *
 * Por isso a identificação do usuário aceita as duas origens. Antes só existia
 * auth()->loggedIn(), que depende de sessão — como o cliente de API manda
 * Bearer e nenhum cookie, a rota da API respondia 401 em 100% das chamadas.
 */
class FavoriteController extends BaseController
{
    public function toggle()
    {
        // auth_user_id é injetado pelo filtro api_auth; auth()->id() cobre a
        // rota web com sessão.
        $userId = $this->request->auth_user_id ?? (auth()->loggedIn() ? auth()->id() : null);

        if (! $userId) {
            return $this->respondError(
                'Você precisa estar autenticado para favoritar.',
                401,
                [],
                self::ERR_UNAUTHORIZED
            );
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        $propertyId = $data['property_id'] ?? null;

        if (! $propertyId || ! is_numeric($propertyId)) {
            return $this->respondError('property_id é obrigatório.', 422, [], self::ERR_VALIDATION);
        }

        $propertyId = (int) $propertyId;

        if (! (new \App\Services\PublicPropertyVisibilityService())->isVisible($propertyId)) {
            return $this->respondNotFound('Imóvel não encontrado.');
        }

        $model    = model('App\Models\PropertyFavoriteModel');
        $existing = $model->where('user_id', $userId)
                          ->where('property_id', $propertyId)
                          ->first();

        if ($existing) {
            $model->delete($existing->id);

            return $this->respondSuccess(
                ['status' => 'removed', 'property_id' => $propertyId],
                'Removido dos favoritos.'
            );
        }

        $model->insert([
            'user_id'     => $userId,
            'property_id' => $propertyId,
        ]);

        return $this->respondSuccess(
            ['status' => 'added', 'property_id' => $propertyId],
            'Adicionado aos favoritos.',
            201
        );
    }
}
