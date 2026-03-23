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
