<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Série diária de visualização de imóvel. Escrita só via
 * `upsertCounters()` (chamado pelo `metrics:flush`) — nunca por
 * `insert()`/`update()` do CRUD padrão, porque a chave primária é composta
 * e o valor é sempre um incremento, não uma substituição.
 */
class PropertyViewDailyModel extends Model
{
    protected $table         = 'property_view_daily';
    protected $primaryKey    = 'property_id';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * UPSERT atômico: soma ao que já existe no dia, não substitui. É o que
     * torna `flushPropertyViews` seguro mesmo se o cron rodar duas vezes
     * sobre o mesmo dia (não deveria, mas soma nunca duplica de forma
     * destrutiva — na pior hipótese conta de novo o que já tinha sido
     * somado, o que só aconteceria se o Redis devolvesse a MESMA contagem
     * duas vezes, cenário que o `flushVisits`/`flushPropertyViews` já evita
     * removendo a chave do Redis só após o apply ter sucesso).
     */
    public function upsertCounters(int $propertyId, string $dia, int $views, int $viewsUnicas): bool
    {
        $db = \Config\Database::connect();

        $db->query(
            'INSERT INTO property_view_daily (property_id, dia, views, views_unicas, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (property_id, dia) DO UPDATE SET
                views = property_view_daily.views + EXCLUDED.views,
                views_unicas = property_view_daily.views_unicas + EXCLUDED.views_unicas,
                updated_at = NOW()',
            [$propertyId, $dia, $views, $viewsUnicas]
        );

        return true;
    }

    /** Total de views/únicas de um imóvel num intervalo [de, até]. */
    public function totalsFor(int $propertyId, string $de, string $ate): array
    {
        $row = $this->builder()
            ->select('COALESCE(SUM(views), 0) as views, COALESCE(SUM(views_unicas), 0) as views_unicas')
            ->where('property_id', $propertyId)
            ->where('dia >=', $de)
            ->where('dia <=', $ate)
            ->get()
            ->getRow();

        return ['views' => (int) ($row->views ?? 0), 'views_unicas' => (int) ($row->views_unicas ?? 0)];
    }

    /** Série dia-a-dia de views de uma conta (todos os imóveis), para o gráfico do painel. */
    public function dailySeriesForAccount(int $accountId, string $de, string $ate): array
    {
        $rows = $this->builder()
            ->select('property_view_daily.dia, SUM(property_view_daily.views) as views')
            ->join('properties', 'properties.id = property_view_daily.property_id')
            ->where('properties.account_id', $accountId)
            ->where('property_view_daily.dia >=', $de)
            ->where('property_view_daily.dia <=', $ate)
            ->groupBy('property_view_daily.dia')
            ->orderBy('property_view_daily.dia', 'ASC')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['dia']] = (int) $row['views'];
        }

        return $out;
    }

    public function totalForAccount(int $accountId, string $de, string $ate): int
    {
        $row = $this->builder()
            ->select('COALESCE(SUM(property_view_daily.views), 0) as total')
            ->join('properties', 'properties.id = property_view_daily.property_id')
            ->where('properties.account_id', $accountId)
            ->where('property_view_daily.dia >=', $de)
            ->where('property_view_daily.dia <=', $ate)
            ->get()
            ->getRow();

        return (int) ($row->total ?? 0);
    }

    /** Remove séries mais antigas que a retenção (spark metrics:prune). */
    public function pruneOlderThan(string $limite): int
    {
        $this->where('dia <', $limite)->delete();

        return $this->db->affectedRows();
    }
}
