<?php

namespace App\Libraries\Integrations\Exceptions;

/**
 * A plataforma externa pediu para diminuir o ritmo (429).
 *
 * Diferente de AuthException: a credencial está boa, só não é hora. O sync
 * para a rodada e devolve PARTIAL — a próxima passada do cron continua de onde
 * parou, sem desligar a integração nem marcar ERROR.
 */
class RateLimitException extends IntegrationException
{
    public function __construct(string $message = 'Limite de requisições da plataforma externa atingido.', private readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    /** Segundos sugeridos pelo header Retry-After, quando informado. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
