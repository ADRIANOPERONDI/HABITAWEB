<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Account extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];
    protected $casts   = [
        'whatsapp_hub_config' => 'json-array',
        'whatsapp_messages_config' => 'json-array',
        'is_verified' => 'boolean',
        'cobranca_leads_isenta' => 'boolean',
    ];

    /**
     * Tipos de conta aceitos e como cada um aparece no site. CONSTRUTORA entrou
     * a pedido do cliente: construtora se cadastrava como imobiliária e o selo
     * de contato dizia "imobiliária".
     */
    public const TIPOS = [
        'IMOBILIARIA' => 'Imobiliária',
        'CONSTRUTORA' => 'Construtora',
        'CORRETOR'    => 'Corretor',
        'PF'          => 'Proprietário',
    ];

    /** "a imobiliária", "a construtora", "o corretor", "o proprietário". */
    private const COM_ARTIGO = [
        'IMOBILIARIA' => 'a imobiliária',
        'CONSTRUTORA' => 'a construtora',
        'CORRETOR'    => 'o corretor',
        'PF'          => 'o proprietário',
    ];

    public static function tipoLabel(?string $tipo): string
    {
        return self::TIPOS[strtoupper((string) $tipo)] ?? 'Anunciante';
    }

    public static function tipoComArtigo(?string $tipo): string
    {
        return self::COM_ARTIGO[strtoupper((string) $tipo)] ?? 'o anunciante';
    }
}
