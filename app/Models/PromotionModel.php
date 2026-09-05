<?php

namespace App\Models;

use CodeIgniter\Model;

class PromotionModel extends Model
{
    protected $table            = 'promotions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\Promotion::class;
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'property_id', 'tipo_promocao', 'data_inicio', 'data_fim', 'ativo',
        'account_id', 'origem', 'periodo', 'payment_transaction_id', 'promotion_package_id'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Casts no MODEL, não só na entity — mesmo alerta do CLAUDE.md, encontrado
     * aqui na prática.
     *
     * Sem isto, `useCasts()` do model volta false e a leitura pula a
     * `DataCaster\Cast\BooleanCast` (que trata 't'/'f' do Postgres) indo direto
     * para a hidratação da Entity, que usa `Entity\Cast\BooleanCast` — uma
     * classe DIFERENTE, no namespace `CodeIgniter\Entity\Cast`, que faz só
     * `(bool) $value`. Como 'f' é string não vazia, `(bool) 'f'` é `true` em
     * PHP: toda promoção INATIVA lida por este model reportava `ativo=true`.
     *
     * Achado ao testar TurboService::deactivateExpired(): o UPDATE gravava 'f'
     * corretamente (confirmado por SQL cru), mas o find() seguinte, no mesmo
     * teste, insistia em mostrar `ativo=true` — nunca foi bug de transação ou
     * cache, sempre foi esta lacuna de cast.
     */
    protected array $casts = [
        'id'          => 'integer',
        'property_id' => 'integer',
        'account_id'  => '?integer',
        'ativo'       => 'boolean',
    ];
}
