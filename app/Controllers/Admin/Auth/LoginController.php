<?php

namespace App\Controllers\Admin\Auth;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Controllers\LoginController as ShieldLoginController;

/**
 * Custom Login Controller for Admin specifically.
 * We extend Shield's LoginController to reuse its logic but change the view.
 */
class LoginController extends ShieldLoginController
{
    /**
     * Displays the login form for admin.
     */
    public function loginView()
    {
        if (auth()->loggedIn()) {
            return redirect()->to(config('Auth')->loginRedirect());
        }

        return view('Admin/Auth/login');
    }

    /**
     * Overriding the successful login to redirect to admin dashboard specifically
     * or use the default Shield behavior.
     */

    /**
     * Custom logout for admin.
     */
    public function logoutAction(): RedirectResponse
    {
        auth()->logout();

        return redirect()->to(site_url('admin/login'))->with('message', 'Você foi desconectado do painel administrativo.');
    }
}
