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
        helper('sys'); // garante audit_log() disponível (este controller não estende App\BaseController)
        log_message('debug', '[LoginController] Iniciando loginAction');
        
        // Rate limiting para evitar força bruta, via CodeIgniter\Throttle\Throttler
        // (token-bucket, 5 tokens / 900s). Roda sobre o cache configurado
        // (Redis) então o limite é compartilhado entre instâncias, ao
        // contrário do contador manual anterior (que resetava por instância).
        // O IP entra na chave via hash: endereços IPv6 (ex.: ::1) contêm ':',
        // um caractere reservado que o handler de cache do CI4 rejeita.
        $ip = $this->request->getIPAddress();
        $throttleKey = 'login_attempt_' . sha1($ip);
        $throttler = service('throttler');

        // "Peek" sem custo (cost=0): consulta o saldo de tokens sem consumir,
        // pra bloquear ANTES de gastar ciclos com bcrypt em quem já estourou
        // o limite, sem penalizar esta própria checagem. Só as duas falhas
        // abaixo (validação e autenticação) de fato consomem um token —
        // login correto nunca é penalizado, igual ao comportamento anterior.
        if (! $throttler->check($throttleKey, 5, 900, 0)) {
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
            $throttler->check($throttleKey, 5, 900);
            log_message('error', '[LoginController] Falha na validação do formulário');
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $credentials = $this->request->getPost(setting('Auth.validFields'));
        $credentials = array_filter($credentials);
        $credentials['password'] = $this->request->getPost('password');
        $remember = (bool) $this->request->getPost('remember');

        // NUNCA logar a senha. Registra apenas o identificador (e-mail) da tentativa.
        log_message('debug', '[LoginController] Tentando autenticar: ' . json_encode([
            'email' => $credentials['email'] ?? null,
        ]));

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $attempt = $authenticator->remember($remember)->attempt($credentials);

        if (! $attempt->isOK()) {
            // Conta a tentativa real de senha incorreta (não só falha de validação de formulário)
            // para que o bloqueio por força bruta acima realmente funcione.
            $throttler->check($throttleKey, 5, 900);

            // Motivo detalhado só no log do servidor; ao usuário, mensagem genérica
            // para não permitir enumeração de e-mails (existe vs. senha errada).
            log_message('error', '[LoginController] Falha na autenticação: ' . $attempt->reason());
            audit_log('auth.login_failed', [
                'metadata' => ['email' => $credentials['email'] ?? null],
            ]);
            return redirect()->to(site_url('admin/login'))->withInput()
                ->with('error', 'Não foi possível autenticar. Verifique seu e-mail e senha.');
        }

        log_message('debug', '[LoginController] Autenticação bem-sucedida, redirecionando...');

        $user = auth()->user();
        audit_log('auth.login_success', [
            'actor_user_id' => $user?->id,
            'account_id'    => $user?->account_id,
            'entity_type'   => 'user',
            'entity_id'     => $user?->id,
        ]);
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
