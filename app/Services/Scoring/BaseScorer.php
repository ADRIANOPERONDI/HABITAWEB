<?php

namespace App\Services\Scoring;

use App\Entities\Property;

abstract class BaseScorer implements PropertyScorerInterface
{
    protected int $score = 0;
    protected array $breakdown = [];
    protected array $suggestions = [];

    /**
     * Pontuação base para todos os imóveis (Total: 55 pts)
     * - Título: 10 pts
     * - Descrição: 15 pts
     * - Preço: 10 pts
     * - Mídia: 15 pts
     * - SEO: 5 pts
     */
    protected function calculateBaseScore(Property $property, int $mediaCount): void
    {
        // 1. Título (10 pts)
        if (!empty($property->titulo) && strlen($property->titulo) > 10) {
            $this->addScore(10, 'Título de Qualidade', 'Título');
        } else {
            $this->suggestions[] = "Melhore o título do anúncio (min 10 caracteres).";
        }

        // 2. Descrição (15 pts)
        if (!empty($property->descricao) && strlen($property->descricao) > 100) {
            $this->addScore(15, 'Descrição Detalhada', 'Descrição');
        } elseif (!empty($property->descricao)) {
            $this->addScore(5, 'Descrição Simples', 'Descrição');
            $this->suggestions[] = "Aumente a descrição para pontuar mais (min 100 caracteres).";
        } else {
            $this->suggestions[] = "Adicione uma descrição do imóvel.";
        }

        // 3. Preço (10 pts)
        if (!empty($property->preco) && $property->preco > 0) {
            $this->addScore(10, 'Preço Definido', 'Preço');
        } else {
            $this->suggestions[] = "Informe o preço do imóvel.";
        }

        // 4. Mídia (15 pts)
        if ($mediaCount >= 10) {
            $this->addScore(15, 'Fotos (10+)', 'Mídia');
        } elseif ($mediaCount >= 5) {
            $this->addScore(10, 'Fotos (5-9)', 'Mídia');
            $this->suggestions[] = "Adicione 10 ou mais fotos para pontuação máxima.";
        } elseif ($mediaCount >= 1) {
            $this->addScore(5, 'Fotos (1-4)', 'Mídia');
            $this->suggestions[] = "Adicione mais fotos para atrair mais clientes.";
        } else {
            $this->suggestions[] = "Seu anúncio não tem fotos. Adicione fotos para aumentar a relevância.";
        }

        // 5. SEO (5 pts)
        if (!empty($property->meta_title) && !empty($property->meta_description)) {
            $this->addScore(5, 'Otimização SEO', 'SEO');
        } else {
            $this->suggestions[] = "Personalize as tags de SEO (Meta Título e Descrição) para atrair mais buscas.";
        }
    }

    protected function addScore(int $points, string $label, string $category = ''): void
    {
        $this->score += $points;
        $key = $category ? "{$category} ($label)" : $label;
        $this->breakdown[$key] = "+{$points}";
    }

    protected function result(): array
    {
        return [
            'score'       => min($this->score, 100),
            'breakdown'   => $this->breakdown,
            'suggestions' => array_unique($this->suggestions)
        ];
    }
}
