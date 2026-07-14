<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use CodeIgniter\Config\Factories;

class MyFavoritesController extends BaseController
{
    public function index()
    {
        $userId = auth()->id();
        $favModel = Factories::models(\App\Models\PropertyFavoriteModel::class);
        $propertyModel = Factories::models(\App\Models\PropertyModel::class);

        // Get favorite property IDs
        $favorites = $favModel->where('user_id', $userId)->findAll();
        $ids = array_column($favorites, 'property_id');

        $properties = [];
        if (!empty($ids)) {
            // Reusing the list logic or simple find
            // Let's use PropertyService::getFeatured logic (with cover image) but filtered by IDs
            // actually simple find is enough, we just need the cover.
            // But lets assume we want to show them nicely.
            
            $builder = $propertyModel->whereIn('properties.id', $ids);
            (new \App\Services\PublicPropertyVisibilityService())->apply($builder);
            $properties = $builder->findAll();
            
            // Hydrate cover images
            $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
            $visibleIds = array_map(static fn ($property) => (int) $property->id, $properties);
            $medias = $visibleIds === [] ? [] : $mediaModel->whereIn('property_id', $visibleIds)->findAll();
            
            $mediaMap = [];
            foreach ($medias as $media) {
                if (!isset($mediaMap[$media->property_id]) || $media->principal) {
                    $mediaMap[$media->property_id] = $media->url;
                }
            }

            foreach ($properties as $prop) {
                $prop->cover_image = $mediaMap[$prop->id] ?? null;
            }
        }

        return view('web/my_favorites', ['properties' => $properties]);
    }
}
