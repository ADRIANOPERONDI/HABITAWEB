<?php

namespace App\Models;

use CodeIgniter\Model;

class SearchDailyModel extends Model
{
    protected $table         = 'search_daily';
    protected $primaryKey    = 'dia';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function upsertCounter(string $dia, array $dims, int $buscas): bool
    {
        [$tipoNegocio, $cidade, $bairro, $tipoImovel, $faixaPreco] = array_pad($dims, 5, '');

        $db = \Config\Database::connect();

        $db->query(
            'INSERT INTO search_daily (dia, tipo_negocio, cidade, bairro, tipo_imovel, faixa_preco, buscas, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (dia, tipo_negocio, cidade, bairro, tipo_imovel, faixa_preco) DO UPDATE SET
                buscas = search_daily.buscas + EXCLUDED.buscas,
                updated_at = NOW()',
            [$dia, $tipoNegocio, $cidade, $bairro, $tipoImovel, $faixaPreco, $buscas]
        );

        return true;
    }

    /** Bairros mais buscados de uma cidade no período — "inteligência de mercado". */
    public function topBairros(string $cidade, string $de, string $ate, int $limit = 10): array
    {
        $rows = $this->builder()
            ->select('bairro, SUM(buscas) as total')
            ->where('cidade', $cidade)
            ->where("bairro != ''")
            ->where('dia >=', $de)
            ->where('dia <=', $ate)
            ->groupBy('bairro')
            ->orderBy('total', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_map(static fn ($r) => ['bairro' => $r['bairro'], 'buscas' => (int) $r['total']], $rows);
    }

    public function totalBuscas(string $de, string $ate, array $filtros = []): int
    {
        $builder = $this->builder()
            ->select('COALESCE(SUM(buscas), 0) as total')
            ->where('dia >=', $de)
            ->where('dia <=', $ate);

        foreach (['tipo_negocio', 'cidade', 'bairro', 'tipo_imovel', 'faixa_preco'] as $campo) {
            if (! empty($filtros[$campo])) {
                $builder->where($campo, $filtros[$campo]);
            }
        }

        return (int) ($builder->get()->getRow()->total ?? 0);
    }

    public function pruneOlderThan(string $limite): int
    {
        $this->where('dia <', $limite)->delete();

        return $this->db->affectedRows();
    }
}
