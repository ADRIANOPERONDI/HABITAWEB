<?php

namespace App\Controllers\Admin\Auth;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
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
            $user = auth()->user();
            if ($user !== null && ! $user->active) {
                /** @var Session $authenticator */
                $authenticator = auth('session')->getAuthenticator();
                if (! $authenticator->hasAction()) {
                    $authenticator->startUpAction('register', $user);
                }

                return redirect()->to(site_url('ativacao/codigo'));
            }

            return redirect()->to(config('Auth')->loginRedirect());
        }

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        if ($authenticator->hasAction()) {
            return redirect()->route('auth-action-show');
        }

        return view('Admin/Auth/login');
    }

    /**
     * Overriding the successful login to redirect to admin dashboard specifically
     * or use the default Shield behavior.
     */

    /**
     * Overriding the successful login to redirect to admin dashboard specifically
     * or use the default Shield behavior.
     */
    public function loginAction(): RedirectResponse
    {
        log_message('debug', '[LoginController] Iniciando loginAction');
        
        // FIXED: Rate limiting to prevent brute force
        $ip = $this->request->getIPAddress();
        $cacheKey = "login_attempt_{$ip}";
        $attempts = cache($cacheKey) ?? 0;
        if ($attempts >= 5) {
            log_message('warning', "Brute force attempt from {$ip}");
            return redirect()->back()->with('error', 'Muitas tentativas. Tente em 15 min.');
        }
        
        $rules = [
            'email'    => config('Auth')->emailValidationRules,
            'password' => [
                'label'  => 'Auth.password',
                'rules'  => 'required',
                'errors' => [
                    'required' => 'A senha é obrigatória',
                ],
            ],
        ];

        if (! $this->validateData($this->request->getPost(), $rules)) {
            cache()->save($cacheKey, $attempts + 1, 900);
            log_message('error', '[LoginController] Falha na validação do formulário');
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $credentials = $this->request->getPost(setting('Auth.validFields'));
        $credentials = array_filter($credentials);
        $credentials['password'] = $this->request->getPost('password');
        $remember = (bool) $this->request->getPost('remember');

        log_message('debug', '[LoginController] Tentando autenticar: ' . json_encode($credentials));

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $attempt = $authenticator->remember($remember)->attempt($credentials);

        if (! $attempt->isOK()) {
            log_message('error', '[LoginController] Falha na autenticação: ' . $attempt->reason());
            return redirect()->to(site_url('admin/login'))->withInput()->with('error', $attempt->reason());
        }

        log_message('debug', '[LoginController] Autenticação bem-sucedida, redirecionando...');
        
        $user = auth()->user();
        if ($user !== null) {
            if ($user->requiresPasswordReset()) {
                return redirect()->to(config('Auth')->forcePasswordResetRedirect());
            }

            if (! $user->active) {
                if (! $authenticator->hasAction()) {
                    $authenticator->startUpAction('register', $user);
                }

                return redirect()->to(site_url('ativacao/codigo'))->withCookies();
            }
        }

        if ($authenticator->hasAction()) {
            return redirect()->route('auth-action-show')->withCookies();
        }

        return redirect()->to(config('Auth')->loginRedirect())->withCookies();
    }

    /**
     * Custom logout for admin.
     */
    public function logoutAction(): RedirectResponse
    {
        auth()->logout();

        return redirect()->to(site_url('admin/login'))->with('message', 'Você foi desconectado do painel administrativo.');
    }
}
