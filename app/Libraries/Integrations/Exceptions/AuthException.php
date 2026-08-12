<?php

namespace App\Libraries\Integrations\Exceptions;

/**
 * Credencial recusada pela plataforma externa (401/403), ou ausente.
 *
 * Tratada à parte porque tem consequência própria: o sync automático é
 * desligado e a integração vai para ERROR. Insistir de 30 em 30 minutos com
 * token inválido só empilha erro e pode levar a bloqueio do lado de lá.
 */
class AuthException extends IntegrationException
{
}
