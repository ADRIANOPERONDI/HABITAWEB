<?php

namespace App\Controllers\Auth;

use App\Services\NotificationService;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Controllers\ActionController;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Entities\User;
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

        // Sem identidade ativa: gera e envia código usando a configuração SMTP do painel.
        if ($identity === null) {
            $code = $this->issueActivationCode($user);
            $sent = $this->sendActivationEmail($user, $code);

            if ($sent) {
                session()->setFlashdata('message', lang('App.activation_code_sent_success'));
            } else {
                session()->setFlashdata('error', lang('App.activation_send_error'));
            }
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
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        $this->action = $authenticator->getAction();

        if (! $this->action instanceof ActionInterface) {
            return redirect()->to(config('Auth')->loginRedirect());
        }

        $user = $authenticator->getPendingUser();
        if ($user === null) {
            return redirect()->to(site_url('admin/login'))->with('error', lang('App.activation_invalid_session'));
        }

        $code = $this->issueActivationCode($user);
        $sent = $this->sendActivationEmail($user, $code);

        if (! $sent) {
            return redirect()->back()->with('error', lang('App.activation_send_error'));
        }

        return redirect()->back()->with('message', lang('App.activation_resend_success'));
    }

    private function issueActivationCode(User $user): string
    {
        /** @var UserIdentityModel $identityModel */
        $identityModel = model(UserIdentityModel::class);

        $identityModel->deleteIdentitiesByType($user, Session::ID_TYPE_EMAIL_ACTIVATE);

        helper('text');

        return $identityModel->createCodeIdentity(
            $user,
            [
                'type'  => Session::ID_TYPE_EMAIL_ACTIVATE,
                'name'  => 'register',
                'extra' => lang('Auth.needVerification'),
            ],
            static fn (): string => random_string('nozero', 6),
        );
    }

    private function sendActivationEmail(User $user, string $code): bool
    {
        $to = $user->email;
        if (empty($to)) {
            return false;
        }

        $request = service('request');

        $message = view(
            setting('Auth.views')['action_email_activate_email'],
            [
                'code' => $code,
                'user' => $user,
                'ipAddress' => $request->getIPAddress(),
                'userAgent' => (string) $request->getUserAgent(),
                'date' => Time::now()->toDateTimeString(),
            ],
        );

        $notificationService = new NotificationService();

        return $notificationService->sendEmail(
            $to,
            lang('App.email_activation_subject'),
            $message,
        );
    }
}
