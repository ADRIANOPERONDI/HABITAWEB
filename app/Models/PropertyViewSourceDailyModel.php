<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyViewSourceDailyModel extends Model
{
    protected $table         = 'property_view_source_daily';
    protected $primaryKey    = 'property_id';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function upsertCounter(int $propertyId, string $dia, string $origem, int $views): bool
    {
        $db = \Config\Database::connect();

        $db->query(
            'INSERT INTO property_view_source_daily (property_id, dia, origem, views, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (property_id, dia, origem) DO UPDATE SET
                views = property_view_source_daily.views + EXCLUDED.views,
                updated_at = NOW()',
            [$propertyId, $dia, $origem, $views]
        );

        return true;
    }

    /** Origem que mais trouxe visualização, por conta, no período — para o "de onde vêm os acessos". */
    public function originsForAccount(int $accountId, string $de, string $ate): array
    {
        $rows = $this->builder()
            ->select('property_view_source_daily.origem, SUM(property_view_source_daily.views) as views')
            ->join('properties', 'properties.id = property_view_source_daily.property_id')
            ->where('properties.account_id', $accountId)
            ->where('property_view_source_daily.dia >=', $de)
            ->where('property_view_source_daily.dia <=', $ate)
            ->groupBy('property_view_source_daily.origem')
            ->orderBy('views', 'DESC')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['origem']] = (int) $row['views'];
        }

        return $out;
    }

    public function pruneOlderThan(string $limite): int
    {
        $this->where('dia <', $limite)->delete();

        return $this->db->affectedRows();
    }
}
