<?php

namespace App\Libraries\Integrations\Exceptions;

/**
 * Falha genérica de um conector de integração.
 *
 * A mensagem destas exceptions CHEGA AO PAINEL do tenant (vira
 * account_integrations.last_test_message), então precisa ser legível em
 * português e nunca pode conter credencial, header ou corpo de resposta cru.
 */
class IntegrationException extends \RuntimeException
{
}
