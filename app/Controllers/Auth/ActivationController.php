<?php

namespace App\Controllers\Auth;

use CodeIgniter\Shield\Controllers\ActionController;

use CodeIgniter\Shield\Authentication\Actions\ActionInterface;

/**
 * Custom Activation Controller to handle activation routes with friendly URLs.
 * Extends Shield's ActionController to maintain all internal logic.
 */
class ActivationController extends ActionController
{
    /**
     * Garante ação de ativação válida antes de executar métodos herdados.
     * Evita 404 quando usuário inativo chega aqui sem contexto de action na sessão.
     *
     * @param list<string> $params
     *
     * @return \CodeIgniter\HTTP\Response|string
     */
    public function _remap(string $method, ...$params)
    {
        /** @var \CodeIgniter\Shield\Authentication\Authenticators\Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $this->action = $authenticator->getAction();

        if (! $this->action instanceof ActionInterface) {
            $user = auth()->user();

            if ($user !== null && ! $user->active) {
                $authenticator->startUpAction('register', $user);
                $this->action = $authenticator->getAction();
            }
        }

        if (! $this->action instanceof ActionInterface) {
            return redirect()->to(site_url('admin/login'))->with('error', 'Sessão de ativação inválida. Faça login novamente.');
        }

        return $this->{$method}(...$params);
    }

    /**
     * Resend the activation email.
     *
     * @return \CodeIgniter\HTTP\RedirectResponse
     */
    public function resend()
    {
        /** @var \CodeIgniter\Shield\Authentication\Authenticators\Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        $this->action = $authenticator->getAction();

        if (! $this->action instanceof ActionInterface) {
            return redirect()->to(config('Auth')->loginRedirect());
        }

        // Re-run the show method to regenerate code and resend email
        $this->action->show();

        return redirect()->back()->with('message', lang('Auth.emailActivateResend'));
    }
}
