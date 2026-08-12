<?php

namespace App\Libraries\Integrations\Dto;

/**
 * Uma imagem do imóvel na plataforma externa, já com a URL montada.
 *
 * A URL precisa ser ESTÁVEL entre sincronizações: PropertyService::addMediaFromUrl()
 * deduplica por sha256(url), então uma URL que muda a cada rodada faria o sync
 * rebaixar o catálogo de fotos inteiro toda vez.
 */
final class ExternalImage
{
    public function __construct(
        public readonly string $url,
        public readonly int $ordem = 0,
        public readonly bool $principal = false,
        public readonly ?string $descricao = null,
    ) {
    }

    public function toMediaOptions(): array
    {
        return [
            'ordem'     => $this->ordem,
            'principal' => $this->principal,
        ];
    }
}
