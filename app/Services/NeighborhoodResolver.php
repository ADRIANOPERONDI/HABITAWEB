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
        if ($registrado) {
            return $registrado->name;
        }

        $nome = NeighborhoodName::normalize($bairro) ?? '';

        if ($register && $nome !== '' && $cidadeKey !== '') {
            $this->register($cidadeKey, $nome, $bairroKey);
        }

        return $nome;
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
