<?php

namespace App\Libraries\Integrations\Dto;

use App\Models\IntegrationSyncRunModel;

/**
 * Contadores de uma rodada de sincronização.
 *
 * Mutável de propósito: o IntegrationSyncService vai incrementando ao longo do
 * laço e no fim entrega para IntegrationSyncRunModel::finish().
 */
final class SyncResult
{
    public int $totalFetched = 0;
    public int $created      = 0;
    public int $updated      = 0;
    public int $skipped      = 0;
    public int $ignored      = 0;
    public int $paused       = 0;
    public int $images       = 0;
    public int $geocoded     = 0;
    public int $errors       = 0;

    /**
     * Fotos que falharam ao baixar (rede, formato) — não invalidam o imóvel,
     * mas ficavam completamente invisíveis: a tela de execuções só mostrava
     * "0 imagem(ns)", sem dizer se foi porque a origem não tinha foto
     * nenhuma ou porque todas falharam.
     */
    public int $imageErrors     = 0;
    /** Fotos descartadas pelo teto de fotos do PLANO, não por falha técnica. */
    public int $photoLimitHits  = 0;

    /** @var string[] */
    public array $errorMessages = [];

    /** Limite de plano estourado: continua atualizando, para de criar. */
    public bool $planLimitReached = false;

    public function addError(string $message): void
    {
        $this->errors++;

        // Guarda só as primeiras: um catálogo com 3000 imóveis quebrados não
        // pode encher a coluna error_message nem a memória do processo.
        if (count($this->errorMessages) < 20) {
            $this->errorMessages[] = $message;
        }
    }

    public function status(): string
    {
        if ($this->planLimitReached || $this->errors > 0) {
            // Erro em item isolado não invalida a rodada inteira — quem
            // sincronizou, sincronizou. ERROR fica para falha fatal, marcada
            // pelo próprio service.
            return IntegrationSyncRunModel::STATUS_PARTIAL;
        }

        return IntegrationSyncRunModel::STATUS_SUCCESS;
    }

    /** No formato das colunas de integration_sync_runs. */
    public function toCounters(): array
    {
        return [
            'total_fetched' => $this->totalFetched,
            'created_count' => $this->created,
            'updated_count' => $this->updated,
            'skipped_count' => $this->skipped,
            'ignored_count' => $this->ignored,
            'paused_count'  => $this->paused,
            'images_count'  => $this->images,
            'error_count'   => $this->errors,
        ];
    }

    public function errorSummary(): ?string
    {
        $partes = [];

        if ($this->errorMessages !== []) {
            $summary = implode(' | ', $this->errorMessages);

            if ($this->errors > count($this->errorMessages)) {
                $summary .= sprintf(' (+%d erro(s) não listado(s))', $this->errors - count($this->errorMessages));
            }

            $partes[] = $summary;
        } elseif ($this->planLimitReached) {
            $partes[] = 'Limite de imóveis do plano atingido: os imóveis existentes continuaram sendo '
                . 'atualizados, mas nenhum novo foi criado.';
        }

        if ($this->imageErrors > 0 || $this->photoLimitHits > 0) {
            $partes[] = sprintf(
                '%d foto(s) falharam ao baixar%s.',
                $this->imageErrors,
                $this->photoLimitHits > 0
                    ? sprintf(' (%d descartada(s) pelo limite de fotos do plano)', $this->photoLimitHits)
                    : ''
            );
        }

        return $partes === [] ? null : implode(' ', $partes);
    }

    /** Resumo curto para o toast do painel e para o output do comando. */
    public function humanSummary(): string
    {
        $resumo = sprintf(
            '%d criado(s), %d atualizado(s), %d sem alteração, %d ignorado(s) (sem mapeamento), %d pausado(s), %d imagem(ns), %d erro(s)',
            $this->created,
            $this->updated,
            $this->skipped,
            $this->ignored,
            $this->paused,
            $this->images,
            $this->errors,
        );

        // geocoded não é persistido em integration_sync_runs (não é um
        // contador de destino/estado do item, é só uma métrica de custo desta
        // rodada) — aparece só no resumo textual do comando/toast.
        if ($this->geocoded > 0) {
            $resumo .= sprintf(', %d geocodificado(s)', $this->geocoded);
        }

        return $resumo;
    }
}
