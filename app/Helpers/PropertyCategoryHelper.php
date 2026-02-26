<?php

namespace App\Helpers;

class PropertyCategoryHelper
{
    /**
     * Define the valid categories and their underlying property types
     */
    public const CATEGORY_RESIDENTIAL = 'RESIDENTIAL';
    public const CATEGORY_COMMERCIAL  = 'COMMERCIAL';
    public const CATEGORY_WAREHOUSE   = 'WAREHOUSE';
    public const CATEGORY_LAND        = 'LAND';

    /**
     * Returns the parent category for a given property type.
     */
    public static function getCategory(string $type): string
    {
        $type = strtoupper($type);
        
        return match ($type) {
            'APARTAMENTO', 'CASA', 'COBERTURA', 'SOBRADO' => self::CATEGORY_RESIDENTIAL,
            'COMERCIAL', 'SALA', 'LOJA'                   => self::CATEGORY_COMMERCIAL,
            'GALPAO'                                      => self::CATEGORY_WAREHOUSE,
            'TERRENO', 'LOTE'                             => self::CATEGORY_LAND,
            default                                       => self::CATEGORY_RESIDENTIAL,
        };
    }

    /**
     * Returns the array of applicable fields for a given property type.
     * Non-applicable fields should be completely ignored in scoring.
     */
    public static function getApplicableFields(string $type): array
    {
        $category = self::getCategory($type);
        
        $baseFields = [
            'titulo', 'descricao', 'preco', 'user_id_responsavel', 
            'cep', 'estado', 'cidade', 'bairro', 'rua', 'numero',
            'latitude', 'longitude', 'is_exclusivo'
        ];

        return match ($category) {
            self::CATEGORY_RESIDENTIAL => array_merge($baseFields, [
                'area_total', 'area_construida', 'quartos', 'suites', 
                'banheiros', 'vagas', 'valor_condominio', 'iptu', 'condominio',
                'aceita_pets', 'mobiliado', 'semimobiliado', 'is_desocupado', 
                'is_locado', 'indicado_investidor', 'indicado_primeira_moradia', 
                'indicado_temporada'
            ]),
            self::CATEGORY_COMMERCIAL => array_merge($baseFields, [
                'area_total', 'area_construida', 'banheiros', 'vagas', 
                'valor_condominio', 'iptu', 'condominio', 
                'is_desocupado', 'is_locado', 'indicado_investidor'
            ]),
            self::CATEGORY_WAREHOUSE => array_merge($baseFields, [
                'area_total', 'area_construida', 'banheiros', 'vagas', 
                'iptu', 'is_desocupado', 'is_locado', 'indicado_investidor'
            ]),
            self::CATEGORY_LAND => array_merge($baseFields, [
                'area_total', 'valor_condominio', 'iptu', 'condominio',
                'indicado_investidor'
            ]),
            default => $baseFields
        };
    }

    /**
     * Check if a specific field is applicable for a given property type
     */
    public static function isFieldApplicable(string $type, string $field): bool
    {
        $applicableFields = self::getApplicableFields($type);
        return in_array($field, $applicableFields);
    }
}
