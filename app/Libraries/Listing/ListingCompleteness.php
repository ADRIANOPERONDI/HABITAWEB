<?php

namespace App\Libraries\Listing;

use App\Helpers\PropertyCategoryHelper;

/**
 * Regra do selo "Anúncio completo".
 *
 * Antes o selo saía com foto + localização + descrição, igual para qualquer
 * tipo — um apartamento sem área, quartos nem preço ganhava o mesmo selo de
 * um cadastro caprichado. Regra acordada com o cliente (revisão da área
 * pública, item 10):
 *  - todo tipo: preço informado (imóvel "sob consulta" não ganha o selo),
 *    localização no mapa e descrição;
 *  - residencial (casa, apartamento, cobertura, sobrado): 5 fotos, área,
 *    quartos e banheiros;
 *  - terreno/lote, comercial e galpão: 3 fotos e área.
 *
 * Quartos/banheiros contam como informados mesmo com 0 (kitnet sem quarto
 * separado é cadastro completo); NULL é "não informado".
 */
final class ListingCompleteness
{
    public const MIN_FOTOS_RESIDENCIAL = 5;
    public const MIN_FOTOS_OUTROS      = 3;

    /**
     * @return array{complete: bool, missing: list<string>, min_photos: int, residential: bool}
     */
    public static function evaluate(object $property, int $photoCount): array
    {
        $residential = PropertyCategoryHelper::getCategory((string) ($property->tipo_imovel ?? ''))
            === PropertyCategoryHelper::CATEGORY_RESIDENTIAL;
        $minPhotos = $residential ? self::MIN_FOTOS_RESIDENCIAL : self::MIN_FOTOS_OUTROS;

        $missing = [];

        if ((float) ($property->preco ?? 0) <= 0) {
            $missing[] = 'preço';
        }
        if ($photoCount < $minPhotos) {
            $missing[] = "pelo menos {$minPhotos} fotos";
        }
        if (empty($property->latitude) || empty($property->longitude)) {
            $missing[] = 'localização no mapa';
        }
        if (trim(strip_tags((string) ($property->descricao ?? ''))) === '') {
            $missing[] = 'descrição';
        }
        if ((float) ($property->area_total ?? 0) <= 0 && (float) ($property->area_construida ?? 0) <= 0) {
            $missing[] = 'área';
        }
        if ($residential) {
            foreach (['quartos' => 'quartos', 'banheiros' => 'banheiros'] as $field => $label) {
                $value = $property->{$field} ?? null;
                if ($value === null || $value === '') {
                    $missing[] = $label;
                }
            }
        }

        return [
            'complete'    => $missing === [],
            'missing'     => $missing,
            'min_photos'  => $minPhotos,
            'residential' => $residential,
        ];
    }

    /** Frase do que o selo garante, para explicar ao visitante. */
    public static function description(bool $residential): string
    {
        return $residential
            ? 'Tem preço, localização no mapa, descrição, pelo menos ' . self::MIN_FOTOS_RESIDENCIAL . ' fotos, área, quartos e banheiros informados.'
            : 'Tem preço, localização no mapa, descrição, pelo menos ' . self::MIN_FOTOS_OUTROS . ' fotos e área informados.';
    }
}
