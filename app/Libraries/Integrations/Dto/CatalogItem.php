<?php

namespace App\Libraries\Integrations\Dto;

/**
 * Um item do catálogo externo com o detalhe ainda NÃO carregado.
 *
 * A listagem do Simob traz id, código e updatedAt; o detalhe (descrição,
 * imagens, características, preços) exige uma requisição por imóvel. Numa
 * imobiliária com 1500 imóveis, buscar o detalhe de todos a cada rodada são
 * 1500 chamadas — quase todas para descobrir que nada mudou.
 *
 * Por isso o catálogo entrega este objeto: o sync compara `externalUpdatedAt`
 * com o que já tem no banco e só chama resolve() no que mudou de verdade.
 */
final class CatalogItem
{
    /** @var \Closure():?ExternalProperty */
    private \Closure $resolver;

    public function __construct(
        public readonly string $externalId,
        public readonly string $externalCode,
        public readonly ?string $externalUpdatedAt,
        callable $resolver,
    ) {
        $this->resolver = \Closure::fromCallable($resolver);
    }

    /** Busca o detalhe e devolve o imóvel já mapeado. Faz I/O. */
    public function resolve(): ?ExternalProperty
    {
        return ($this->resolver)();
    }
}
