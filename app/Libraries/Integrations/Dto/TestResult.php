<?php

namespace App\Libraries\Integrations\Dto;

/**
 * Resultado de um "Testar conexão" ou de um envio pontual (push de lead).
 *
 * `message` vai direto para a tela do tenant: escreva em português, sem jargão
 * e sem nada que tenha vindo cru da plataforma externa.
 */
final class TestResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $details = [],
    ) {
    }

    public static function ok(string $message, array $details = []): self
    {
        return new self(true, $message, $details);
    }

    public static function fail(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
