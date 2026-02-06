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
            
            $properties = $propertyModel->whereIn('id', $ids)->findAll();
            
            // Hydrate cover images
            $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
            $medias = $mediaModel->whereIn('property_id', $ids)->findAll();
            
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
