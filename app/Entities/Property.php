<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Property extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at', 'last_validated_at'];
    protected $casts   = [
        'quality_warnings' => 'json',
        'auto_paused'      => 'boolean',
        'is_destaque'      => 'boolean',
        'is_novo'          => 'boolean',
        'is_exclusivo'     => 'boolean',
        'aceita_pets'      => 'boolean',
        'mobiliado'        => 'boolean',
        'semimobiliado'    => 'boolean',
        'is_desocupado'    => 'boolean',
        'is_locado'        => 'boolean',
        'indicado_investidor'      => 'boolean',
        'indicado_primeira_moradia' => 'boolean',
        'indicado_temporada'       => 'boolean',
    ];

    /**
     * PostgreSQL retorna booleans como 't'/'f'. O cast nativo do CI4 (bool)
     * avalia 'f' como true. Sobrescrevemos para garantir o valor correto.
     */
    protected function castAs($value, string $attribute, string $method = 'get')
    {
        if ($attribute === 'boolean') {
            if ($value === 'f' || $value === 'false' || $value === 0 || $value === '0') {
                return false;
            }
            if ($value === 't' || $value === 'true' || $value === 1 || $value === '1') {
                return true;
            }
        }

        return parent::castAs($value, $attribute, $method);
    }

    /**
     * Retorna o Meta Título SEO ou gera um automático.
     */
    public function getMetaTitle(): string
    {
        if (!empty($this->attributes['meta_title'])) {
            return $this->attributes['meta_title'];
        }

        $tipo = ucfirst(strtolower($this->attributes['tipo_imovel'] ?? 'Imóvel'));
        $negocio = ($this->attributes['tipo_negocio'] ?? 'VENDA') === 'VENDA' ? 'à venda' : 'para aluguel';
        $bairro = $this->attributes['bairro'] ?? '';
        $cidade = $this->attributes['cidade'] ?? '';

        return "{$tipo} {$negocio} em {$bairro}, {$cidade}";
    }

    /**
     * Retorna a Meta Descrição SEO ou gera um resumo automático.
     */
    public function getMetaDescription(): string
    {
        if (!empty($this->attributes['meta_description'])) {
            return $this->attributes['meta_description'];
        }

        $quartos = $this->attributes['quartos'] ?? 0;
        $area = (int)($this->attributes['area_total'] ?? 0);
        $titulo = $this->attributes['titulo'] ?? '';

        return "{$titulo}. Imóvel com {$quartos} dormitórios e {$area}m² de área total. Entre em contato para mais informações.";
    }
}
