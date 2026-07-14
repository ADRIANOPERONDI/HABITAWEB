<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\ClientModel;

class PageController extends BaseController
{
    private function calculateYearsExperience(): int
    {
        $foundationYear = (int) app_setting('about.foundation_year', 0);
        $currentYear = (int) date('Y');

        if ($foundationYear <= 0 || $foundationYear > $currentYear) {
            return 0;
        }

        return $currentYear - $foundationYear;
    }

    public function sobre()
    {
        $clientModel = model(ClientModel::class);
        $propertyService = service('propertyService');

        return view('web/sobre', [
            'heroTitle' => app_setting('about.hero_title', 'Sobre a nossa empresa'),
            'heroSubtitle' => app_setting('about.hero_subtitle', ''),
            'heroImage' => app_setting('about.hero_image', ''),
            'storyTitle' => app_setting('about.story_title', 'Nossa história'),
            'storyContent' => app_setting('about.story_content', ''),
            'missionTitle' => app_setting('about.mission_title', 'Missão'),
            'missionText' => app_setting('about.mission_text', ''),
            'visionTitle' => app_setting('about.vision_title', 'Visão'),
            'visionText' => app_setting('about.vision_text', ''),
            'valuesTitle' => app_setting('about.values_title', 'Nossos valores'),
            'valuesContent' => app_setting('about.values_content', ''),
            'statsExperience' => $this->calculateYearsExperience(),
            'statsClients' => $clientModel->countRegisteredClients(),
            'statsProperties' => $propertyService->countPublicProperties(),
            'ctaTitle' => app_setting('about.cta_title', ''),
            'ctaText' => app_setting('about.cta_text', ''),
        ]);
    }

    public function termos()
    {
        return view('web/termos', [
            'conteudo' => app_setting('legal.terms_of_use', ''),
        ]);
    }

    public function privacidade()
    {
        return view('web/privacidade', [
            'conteudo' => app_setting('legal.privacy_policy', ''),
        ]);
    }
}
