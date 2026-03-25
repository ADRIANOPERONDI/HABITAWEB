<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class PageController extends BaseController
{
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
