<?php

namespace App\Controllers\Auth;

use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Controllers\ActionController;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Models\UserIdentityModel;

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
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $this->action = $authenticator->getAction();

        if (! $this->action instanceof ActionInterface) {
            $user = auth()->user();

            if ($user !== null && ! $user->active) {
                // Reaproveita a action existente no banco sem recriar código sempre.
                if (! $authenticator->hasAction($user->id)) {
                    $authenticator->startUpAction('register', $user);
                }

                $this->action = $authenticator->getAction();
            }
        }

        if (! $this->action instanceof ActionInterface) {
            return redirect()->to(site_url('admin/login'))->with('error', 'Sessão de ativação inválida. Faça login novamente.');
        }

        return $this->{$method}(...$params);
    }

    /**
     * Exibe tela de ativação sem reenviar e-mail/código a cada refresh.
     * O reenvio explícito permanece em /ativacao/reenviar.
     *
     * @return \CodeIgniter\HTTP\Response|string
     */
    public function show()
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        $user = $authenticator->getPendingUser();
        if ($user === null) {
            return redirect()->to(site_url('admin/login'))->with('error', 'Sessão de ativação inválida. Faça login novamente.');
        }

        /** @var UserIdentityModel $identityModel */
        $identityModel = model(UserIdentityModel::class);
        $identity = $identityModel->getIdentityByType($user, Session::ID_TYPE_EMAIL_ACTIVATE);

        // Sem identidade ativa: executa fluxo padrão (gera código e envia e-mail).
        if ($identity === null) {
            return $this->action->show();
        }

        // Com identidade existente: só renderiza a tela, sem trabalho pesado.
        return view(setting('Auth.views')['action_email_activate_show'], ['user' => $user]);
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
