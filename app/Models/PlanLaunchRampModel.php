<?php

namespace App\Models;

use CodeIgniter\Model;

class PlanLaunchRampModel extends Model
{
    protected $table         = 'plan_launch_ramps';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['mes_de', 'mes_ate', 'percentual', 'is_active', 'valid_from', 'valid_to'];

    protected array $casts = [
        'mes_de'     => 'integer',
        'mes_ate'    => '?integer',
        'percentual' => 'integer',
        'is_active'  => 'boolean',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Faixa vigente para um mês de vida da conta, ou null (fora de qualquer
     * faixa configurada — quem chama trata como "cobra cheio").
     */
    public function forMonth(int $mesVida, ?string $onDate = null): ?array
    {
        $onDate ??= date('Y-m-d');

        return $this->where('is_active', true)
            ->where('mes_de <=', $mesVida)
            ->groupStart()
                ->where('mes_ate IS NULL', null, false)
                ->orWhere('mes_ate >=', $mesVida)
            ->groupEnd()
            ->where('valid_from <=', $onDate)
            ->groupStart()
                ->where('valid_to IS NULL', null, false)
                ->orWhere('valid_to >=', $onDate)
            ->groupEnd()
            ->orderBy('mes_de', 'DESC')
            ->first();
    }

    /**
     * Próxima faixa depois da que cobre `$mesVidaAtual`, ou null se a faixa
     * atual já é aberta (`mes_ate` nulo — não há próxima transição).
     */
    public function next(int $mesVidaAtual, ?string $onDate = null): ?array
    {
        $atual = $this->forMonth($mesVidaAtual, $onDate);

        if ($atual === null || $atual['mes_ate'] === null) {
            return null;
        }

        return $this->forMonth((int) $atual['mes_ate'] + 1, $onDate);
    }
}
