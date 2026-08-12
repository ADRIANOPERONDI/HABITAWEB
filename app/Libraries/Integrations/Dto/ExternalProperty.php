<?php

namespace App\Libraries\Integrations\Dto;

/**
 * Um imóvel da plataforma externa já traduzido para o vocabulário do Habitaweb.
 *
 * É a fronteira da lib: tudo que é específico do Simob (finalidade 1/2,
 * caracteristicas com idTipoCaracteristica, configVenda/configLocacao) morre no
 * mapper do conector e não vaza para o IntegrationSyncService.
 *
 * `fields` já vem no formato aceito por PropertyService::trySaveProperty().
 */
final class ExternalProperty
{
    /**
     * @param array<string, mixed> $fields    Colunas de properties prontas para o upsert
     * @param ExternalImage[]      $images
     * @param array<string, mixed> $raw       Payload original, só para debug/hash
     */
    public function __construct(
        public readonly string $externalId,
        public readonly array $fields,
        public readonly array $images = [],
        public readonly ?string $externalCode = null,
        public readonly ?string $externalUpdatedAt = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Impressão digital do conteúdo relevante, para pular imóvel inalterado.
     *
     * Calculada sobre `fields` + URLs das imagens, e não sobre `raw`: o payload
     * cru costuma trazer campos voláteis (contadores de visita, valores
     * recalculados na hora) que mudariam o hash a cada rodada e anulariam a
     * economia. As chaves são ordenadas para o hash não depender da ordem em
     * que a origem devolveu os campos.
     */
    public function contentHash(): string
    {
        $fields = $this->fields;
        ksort($fields);

        $imageUrls = array_map(static fn (ExternalImage $i) => $i->url, $this->images);
        sort($imageUrls);

        return hash('sha256', json_encode([
            'fields' => $fields,
            'images' => $imageUrls,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function withFields(array $fields): self
    {
        return new self(
            $this->externalId,
            $fields,
            $this->images,
            $this->externalCode,
            $this->externalUpdatedAt,
            $this->raw,
        );
    }
}
