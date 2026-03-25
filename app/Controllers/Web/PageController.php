<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class PageController extends BaseController
{
    public function sobre()
    {
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
            'statsExperience' => (int) app_setting('about.stats_experience', 0),
            'statsClients' => (int) app_setting('about.stats_clients', 0),
            'statsProperties' => (int) app_setting('about.stats_properties', 0),
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
