<?php

namespace App\Services;

use App\Libraries\Text\CityName;
use App\Libraries\Text\NeighborhoodName;
use CodeIgniter\Database\BaseConnection;

/**
 * Resolve o texto de bairro vindo de qualquer origem (formulário admin, import
 * por API/CSV, Simob) para o nome canônico dentro da cidade.
 *
 * Ordem de resolução, sempre escopada por cidade — "Centro" de uma cidade
 * nunca se mistura com o de outra:
 *   1. alias manual (neighborhood_aliases): "Centro Histórico" -> "Centro";
 *   2. bairro já registrado com a mesma chave (sem caixa/acento/espaço extra):
 *      devolve o nome registrado, então "SAO JORGE" cai em "São Jorge";
 *   3. bairro novo: Title Case (NeighborhoodName::normalize) e registro, para
 *      que a próxima variante dele caia no passo 2.
 *
 * Nada aqui funde dois nomes realmente distintos por conta própria: isso só
 * acontece via alias, que é decisão humana.
 */
class NeighborhoodResolver
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    public static function cityKey(?string $cidade): string
    {
        return NeighborhoodName::key(CityName::normalize($cidade));
    }

    /**
     * @return string|null nome canônico; null se o bairro veio vazio
     */
    public function resolve(?string $cidade, ?string $bairro, bool $register = true): ?string
    {
        if ($bairro === null || trim($bairro) === '') {
            return $bairro === null ? null : '';
        }

        $cidadeKey = self::cityKey($cidade);
        $bairroKey = NeighborhoodName::key($bairro);

        $conhecido = $this->lookup($cidadeKey, $bairroKey);
        if ($conhecido !== null) {
            return $conhecido;
        }

        $nome = NeighborhoodName::normalize($bairro) ?? '';

        if ($register && $nome !== '' && $cidadeKey !== '') {
            $this->register($cidadeKey, $nome, $bairroKey);
        }

        return $nome;
    }

    /** Nome canônico já conhecido (alias ou bairro registrado), ou null. */
    public function lookup(string $cidadeKey, string $bairroKey): ?string
    {
        // Antes da migration as tabelas nao existem: o dry-run do backfill
        // precisa funcionar assim mesmo (registro vazio), para dar para ver o
        // que mudaria ANTES de migrar.
        if (! $this->db->tableExists('neighborhoods')) {
            return null;
        }

        $alias = $this->db->table('neighborhood_aliases a')
            ->select('n.name')
            ->join('neighborhoods n', 'n.id = a.neighborhood_id')
            ->where('a.cidade_key', $cidadeKey)
            ->where('a.alias_key', $bairroKey)
            ->get()->getRow();
        if ($alias) {
            return $alias->name;
        }

        $registrado = $this->db->table('neighborhoods')
            ->select('name')
            ->where('cidade_key', $cidadeKey)
            ->where('normalized_key', $bairroKey)
            ->get()->getRow();

        return $registrado ? $registrado->name : null;
    }

    /**
     * Unifica as grafias de bairro já gravadas em `properties`.
     *
     * Agrupa por (cidade, chave do bairro) — nunca entre cidades, nunca entre
     * nomes de chave diferente. O nome canônico do grupo é o já registrado
     * (ou alias); se não há, a melhor grafia existente: com acento > caixa
     * mista > a mais frequente. Cada grafia diferente da canônica vira um
     * UPDATE.
     *
     * @return list<array{cidade: string, canonico: string, variantes: list<string>, imoveis: int}> um item por grupo que mudaria
     */
    public function backfill(bool $dryRun = true): array
    {
        $linhas = $this->db->table('properties')
            ->select('cidade, bairro, COUNT(*) AS total')
            ->where("TRIM(COALESCE(bairro, '')) !=", '')
            ->groupBy('cidade, bairro')
            ->get()->getResultArray();

        $grupos = [];
        foreach ($linhas as $l) {
            $chave = self::cityKey($l['cidade']) . '|' . NeighborhoodName::key($l['bairro']);
            $grupos[$chave][] = $l;
        }

        $relatorio = [];
        foreach ($grupos as $chave => $variantes) {
            [$cidadeKey, $bairroKey] = explode('|', $chave, 2);

            $canonico = $this->lookup($cidadeKey, $bairroKey);
            if ($canonico === null) {
                usort($variantes, static function (array $a, array $b): int {
                    $score = static fn (array $v): int => (preg_match('/[^\x00-\x7F]/u', $v['bairro']) ? 1000000 : 0)
                        + ($v['bairro'] !== mb_strtoupper($v['bairro'], 'UTF-8') && $v['bairro'] !== mb_strtolower($v['bairro'], 'UTF-8') ? 100000 : 0)
                        + (int) $v['total'];

                    return $score($b) <=> $score($a);
                });
                $canonico = NeighborhoodName::normalize($variantes[0]['bairro']) ?? '';
                if (! $dryRun && $canonico !== '' && $cidadeKey !== '') {
                    $this->register($cidadeKey, $canonico, $bairroKey);
                }
            }

            $mudam = array_values(array_filter($variantes, static fn (array $v): bool => $v['bairro'] !== $canonico));
            if ($mudam === [] || $canonico === '') {
                continue;
            }

            if (! $dryRun) {
                foreach ($mudam as $v) {
                    $this->db->table('properties')
                        ->where('cidade', $v['cidade'])
                        ->where('bairro', $v['bairro'])
                        ->update(['bairro' => $canonico]);
                }
            }

            $relatorio[] = [
                'cidade'    => $variantes[0]['cidade'],
                'canonico'  => $canonico,
                'variantes' => array_map(static fn (array $v): string => $v['bairro'], $mudam),
                'imoveis'   => array_sum(array_map(static fn (array $v): int => (int) $v['total'], $mudam)),
            ];
        }

        if (! $dryRun && $relatorio !== []) {
            PublicPropertyVisibilityService::invalidateCaches();
        }

        return $relatorio;
    }

    /** Registra o bairro canônico; idempotente. */
    public function register(string $cidadeKey, string $nome, ?string $bairroKey = null): int
    {
        $bairroKey ??= NeighborhoodName::key($nome);

        $existente = $this->db->table('neighborhoods')
            ->select('id')->where('cidade_key', $cidadeKey)->where('normalized_key', $bairroKey)
            ->get()->getRow();
        if ($existente) {
            return (int) $existente->id;
        }

        $agora = date('Y-m-d H:i:s');
        $this->db->table('neighborhoods')->insert([
            'cidade_key'     => $cidadeKey,
            'name'           => $nome,
            'normalized_key' => $bairroKey,
            'created_at'     => $agora,
            'updated_at'     => $agora,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Associa um nome diferente ao bairro correto (ex.: "Centro Histórico" ->
     * "Centro"). Cria o bairro de destino se ele ainda não estiver registrado.
     */
    public function addAlias(string $cidade, string $alias, string $bairroCanonico, string $source = 'manual'): void
    {
        $cidadeKey = self::cityKey($cidade);
        $nome      = NeighborhoodName::normalize($bairroCanonico) ?? '';
        $destino   = $this->register($cidadeKey, $nome);
        $aliasKey  = NeighborhoodName::key($alias);

        if ($aliasKey === NeighborhoodName::key($nome)) {
            return; // o próprio nome não precisa de alias
        }

        $existe = $this->db->table('neighborhood_aliases')
            ->where('cidade_key', $cidadeKey)->where('alias_key', $aliasKey)->countAllResults();
        if ($existe > 0) {
            $this->db->table('neighborhood_aliases')
                ->where('cidade_key', $cidadeKey)->where('alias_key', $aliasKey)
                ->update(['neighborhood_id' => $destino, 'source' => $source]);

            return;
        }

        $this->db->table('neighborhood_aliases')->insert([
            'neighborhood_id' => $destino,
            'cidade_key'      => $cidadeKey,
            'alias_key'       => $aliasKey,
            'source'          => $source,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}
