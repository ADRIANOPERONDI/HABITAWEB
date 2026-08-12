<?php

namespace App\Libraries\Integrations\Simob;

use App\Entities\IntegrationMapping;
use App\Libraries\Integrations\Dto\ExternalImage;
use App\Libraries\Integrations\Dto\ExternalProperty;

/**
 * Traduz o payload do Simob para as colunas de `properties`.
 *
 * É a fronteira do conector: nada de "finalidade", "idTipoCaracteristica" ou
 * "configLocacao" atravessa daqui para o IntegrationSyncService.
 *
 * O Simob devolve DOIS formatos para o mesmo imóvel, e eles não coincidem:
 *
 *   listagem (filtro/destaques)   detalhe (detalhes/imovel/{codigo})
 *   ---------------------------   ---------------------------------
 *   idCategoria/descricaoCategoria  categoria: {id, descricao}
 *   caracteristicas[].tipoCaracteristica  caracteristicas[].tipo
 *   imagens[].ordem                 imagens[].posicao
 *   valor (da finalidade buscada)   configVenda.valor / configLocacao.valor
 *   descricaoImovel                 descricao
 *   (sem título)                    nome
 *
 * O mapeamento real usa sempre o DETALHE, porque só ele diz se o imóvel está à
 * venda, para alugar ou os dois — a listagem só mostra o lado pelo qual você
 * perguntou. A listagem serve para paginar e para o corte incremental.
 */
class SimobPropertyMapper
{
    /** Características tipo 1 são Sim/Não. */
    private const TIPO_SIM_NAO = 1;
    private const TIPO_TEXTO   = 2;
    private const TIPO_INTEIRO = 3;
    private const TIPO_DECIMAL = 4;
    private const TIPO_MOEDA   = 5;

    private const INT_FIELDS   = ['quartos', 'suites', 'banheiros', 'vagas'];
    private const BOOL_FIELDS  = ['mobiliado', 'semimobiliado', 'aceita_pets', 'is_desocupado'];
    private const FLOAT_FIELDS = ['area_total', 'area_construida', 'area_privativa', 'valor_condominio', 'iptu'];

    /**
     * @param array<string, IntegrationMapping> $categoryMap       idCategoria  => mapping
     * @param array<string, IntegrationMapping> $characteristicMap idCaracteristica => mapping
     */
    public function __construct(
        private string $baseUrl,
        private array $categoryMap = [],
        private array $characteristicMap = [],
        private array $settings = [],
    ) {
    }

    /**
     * Dados mínimos de um item da LISTAGEM, para decidir se vale buscar o detalhe.
     *
     * @param array<string, mixed> $item
     *
     * @return array{external_id:string, external_code:string, updated_at:?string}|null
     */
    public function summarize(array $item): ?array
    {
        $id = (string) ($item['id'] ?? '');

        if ($id === '') {
            return null;
        }

        return [
            'external_id'   => $id,
            'external_code' => (string) ($item['codigo'] ?? $id),
            'updated_at'    => $this->cleanDate($item['updatedAt'] ?? null),
        ];
    }

    /**
     * Payload de DETALHE -> ExternalProperty.
     *
     * @param array<string, mixed> $detail
     * @param array<string, mixed> $listItem Item da listagem, se houver: traz
     *                                       updatedAt, que o detalhe não devolve
     */
    public function mapDetail(array $detail, array $listItem = []): ?ExternalProperty
    {
        $externalId = (string) ($detail['id'] ?? $listItem['id'] ?? '');

        if ($externalId === '') {
            return null;
        }

        $fields = [];

        [$tipoNegocio, $preco] = $this->resolveNegocioEPreco($detail, $listItem);

        // Sem preço nem tipo de negócio não há imóvel publicável: o
        // validatePropertyData rejeitaria depois, com mensagem pior.
        if ($tipoNegocio === null) {
            return null;
        }

        $fields['tipo_negocio'] = $tipoNegocio;
        $fields['preco']        = $preco;
        $fields['tipo_imovel']  = $this->resolveTipoImovel($detail, $listItem);

        $fields += $this->mapAddress($detail, $listItem);

        $caracteristicas = $this->extractCaracteristicas($detail, $listItem);
        [$mapped, $leftovers] = $this->mapCaracteristicas($caracteristicas);
        $fields += $mapped;

        $fields['titulo']    = $this->buildTitle($detail, $listItem, $fields);
        $fields['descricao'] = $this->buildDescription($detail, $listItem, $leftovers);
        $fields['status']    = $this->resolveStatus($detail);

        if (($detail['exclusividade'] ?? $listItem['exclusividade'] ?? null) !== null) {
            $fields['is_exclusivo'] = (int) ($detail['exclusividade'] ?? $listItem['exclusividade']) > 0;
        }

        // O IPTU tem endpoint próprio no Simob e vem melhor no detalhe do que
        // como característica — só sobrescreve se a característica não trouxe.
        $iptu = $this->parseNumber($detail['iptu']['valorMensal'] ?? null);

        if ($iptu !== null && $iptu > 0 && ! isset($fields['iptu'])) {
            $fields['iptu'] = $iptu;
        }

        return new ExternalProperty(
            externalId: $externalId,
            fields: array_filter($fields, static fn ($v) => $v !== null && $v !== ''),
            images: $this->mapImages($detail, $listItem, $externalId),
            externalCode: (string) ($detail['codigo'] ?? $listItem['codigo'] ?? $externalId),
            externalUpdatedAt: $this->cleanDate($listItem['updatedAt'] ?? $detail['updatedAt'] ?? null),
            raw: $detail,
        );
    }

    // ------------------------------------------------------------- negócio

    /**
     * VENDA, ALUGUEL ou VENDA_ALUGUEL, e o preço correspondente.
     *
     * configVenda e configLocacao aparecem os dois no detalhe: é a única fonte
     * que diz de verdade em quais finalidades o imóvel está. Quando está nas
     * duas, o preço adotado é o de VENDA — é o número que o portal mostra
     * primeiro e o que faz o filtro de faixa de preço fazer sentido.
     *
     * @return array{0: string|null, 1: float}
     */
    private function resolveNegocioEPreco(array $detail, array $listItem): array
    {
        $venda   = $this->activeConfig($detail['configVenda'] ?? null);
        $locacao = $this->activeConfig($detail['configLocacao'] ?? null);

        if ($venda !== null && $locacao !== null) {
            return ['VENDA_ALUGUEL', $venda];
        }

        if ($venda !== null) {
            return ['VENDA', $venda];
        }

        if ($locacao !== null) {
            return ['ALUGUEL', $locacao];
        }

        // Detalhe sem config (a doc marca "Dados Imóvel" como nem sempre
        // online): cai para a finalidade pela qual a listagem devolveu o item.
        $finalidade = (int) ($listItem['finalidade'] ?? 0);
        $valor      = $this->parseNumber($listItem['valor'] ?? null) ?? 0.0;

        return match ($finalidade) {
            SimobClient::FINALIDADE_LOCACAO => ['ALUGUEL', $valor],
            SimobClient::FINALIDADE_VENDA   => ['VENDA', $valor],
            default                         => [null, 0.0],
        };
    }

    /**
     * Preço de uma config, ou null se ela não conta.
     *
     * `inativo` e `disponibilizarPortal` são do lado do Simob: um imóvel
     * inativo ou não liberado para portal não pode aparecer no Habitaweb.
     */
    private function activeConfig(mixed $config): ?float
    {
        if (! is_array($config)) {
            return null;
        }

        if (! empty($config['inativo'])) {
            return null;
        }

        if (array_key_exists('disponibilizarPortal', $config) && empty($config['disponibilizarPortal'])) {
            return null;
        }

        return $this->parseNumber($config['valor'] ?? null) ?? 0.0;
    }

    private function resolveStatus(array $detail): string
    {
        $default = (string) ($this->settings['initial_status'] ?? 'DRAFT');

        // Alugado/vendido com data de saída prevista continua no ar no Simob;
        // aqui entra pausado até a saída acontecer.
        if (! empty($detail['dataPrevSaida'])) {
            return 'PAUSED';
        }

        if (! empty($detail['reservado'])) {
            return 'PAUSED';
        }

        return in_array($default, ['DRAFT', 'ACTIVE', 'PAUSED'], true) ? $default : 'DRAFT';
    }

    private function resolveTipoImovel(array $detail, array $listItem): string
    {
        $categoriaId = (string) ($detail['categoria']['id'] ?? $listItem['idCategoria'] ?? '');

        if ($categoriaId !== '' && isset($this->categoryMap[$categoriaId])) {
            $target = $this->categoryMap[$categoriaId]->target_value;

            if (! empty($target)) {
                return (string) $target;
            }
        }

        // Sem de/para cadastrado, tenta o palpite pelo nome e, no limite, cai
        // em CASA — tipo_imovel é obrigatório e um imóvel sem categoria
        // mapeada não pode travar a rodada inteira.
        $descricao = (string) ($detail['categoria']['descricao'] ?? $listItem['descricaoCategoria'] ?? '');

        return SimobVocabulary::guessPropertyType($descricao) ?? 'CASA';
    }

    // ------------------------------------------------------------- endereço

    private function mapAddress(array $detail, array $listItem): array
    {
        $get = static fn (string $key) => $detail[$key] ?? $listItem[$key] ?? null;

        $uf = strtoupper(trim((string) ($get('uf') ?? '')));

        return array_filter([
            'rua'         => $this->str($get('endereco')),
            'numero'      => $this->str($get('numero')),
            'complemento' => $this->str($get('complemento')),
            'bairro'      => $this->str($get('bairro')),
            'cidade'      => $this->str($get('cidade')),
            // validatePropertyData exige exatamente 2 caracteres.
            'estado'      => strlen($uf) === 2 ? $uf : null,
            'cep'         => $this->str($get('cep')),
            'latitude'    => $this->parseNumber($detail['endereco']['localizacao']['latitude'] ?? null),
            'longitude'   => $this->parseNumber($detail['endereco']['localizacao']['longitude'] ?? null),
        ], static fn ($v) => $v !== null);
    }

    // ------------------------------------------------------- características

    /** @return list<array<string, mixed>> */
    private function extractCaracteristicas(array $detail, array $listItem): array
    {
        $list = $detail['caracteristicas'] ?? $listItem['caracteristicas'] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /**
     * Aplica o de/para do tenant.
     *
     * @return array{0: array<string, mixed>, 1: list<array{label:string, valor:string}>}
     *         [colunas preenchidas, características sem destino]
     */
    private function mapCaracteristicas(array $caracteristicas): array
    {
        $fields    = [];
        $leftovers = [];

        foreach ($caracteristicas as $carac) {
            $id    = (string) ($carac['id'] ?? '');
            $label = trim((string) ($carac['descricao'] ?? ''));
            $valor = $carac['valor'] ?? null;
            $tipo  = (int) ($carac['tipo'] ?? $carac['tipoCaracteristica'] ?? $carac['idTipoCaracteristica'] ?? 0);

            $targetField = null;

            if ($id !== '' && isset($this->characteristicMap[$id])) {
                $targetField = $this->characteristicMap[$id]->target_field;
            } elseif ($id === '' || ! array_key_exists($id, $this->characteristicMap)) {
                // Característica que apareceu depois da última descoberta de
                // mapeamentos: usa o palpite para não perder o dado.
                $targetField = SimobVocabulary::guessTargetField($label);
            }

            if (empty($targetField)) {
                // Sem destino, mas não se joga fora: vai para o fim da
                // descrição. É informação que o corretor digitou.
                if ($label !== '' && $this->str($valor) !== null) {
                    $leftovers[] = ['label' => $label, 'valor' => (string) $valor];
                }

                continue;
            }

            $cast = $this->castCaracteristica($targetField, $valor, $tipo);

            if ($cast !== null) {
                $fields[$targetField] = $cast;
            }
        }

        return [$fields, $leftovers];
    }

    /**
     * Converte o valor conforme o tipo declarado pelo Simob e a coluna destino.
     *
     * A coluna manda mais que o tipo da origem: uma imobiliária cadastra
     * "Dormitórios" como texto (tipo 2) e ainda assim `quartos` tem que virar
     * inteiro.
     */
    private function castCaracteristica(string $targetField, mixed $valor, int $tipo): int|float|bool|string|null
    {
        if (in_array($targetField, self::BOOL_FIELDS, true)) {
            return $this->parseBool($valor, $tipo);
        }

        if (in_array($targetField, self::INT_FIELDS, true)) {
            $n = $this->parseNumber($valor);

            return $n === null ? null : max(0, (int) round($n));
        }

        if (in_array($targetField, self::FLOAT_FIELDS, true)) {
            $n = $this->parseNumber($valor);

            return $n === null || $n < 0 ? null : $n;
        }

        return $this->str($valor);
    }

    /**
     * Sim/Não do Simob -> booleano.
     *
     * O tipo 1 chega como "Sim"/"Não", mas na listagem a mesma característica
     * às vezes vem como "1"/"0". Os dois precisam funcionar.
     */
    private function parseBool(mixed $valor, int $tipo): ?bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        $normalized = SimobVocabulary::normalize((string) ($valor ?? ''));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['sim', 's', 'true', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['nao', 'n', 'false', 'no'], true)) {
            return false;
        }

        if (is_numeric($normalized)) {
            return (float) $normalized > 0;
        }

        // Tipo 1 com texto livre ("Sim, 2 vagas") ainda significa que tem.
        return $tipo === self::TIPO_SIM_NAO ? true : null;
    }

    // --------------------------------------------------------- texto e mídia

    private function buildTitle(array $detail, array $listItem, array $fields): string
    {
        // O `nome` do detalhe já é o rótulo que a imobiliária usa
        // ("3364 - RUA A - N° 04 - PROGRESSO"), mas começa com o código, que
        // não interessa a quem navega no portal.
        $nome = trim((string) ($detail['nome'] ?? ''));

        if ($nome !== '') {
            $semCodigo = trim(preg_replace('/^\s*\d+\s*-\s*/', '', $nome) ?? $nome);

            if ($semCodigo !== '') {
                return mb_substr($semCodigo, 0, 255);
            }
        }

        $tipo   = SimobVocabulary::propertyTypes()[$fields['tipo_imovel'] ?? ''] ?? 'Imóvel';
        $partes = array_filter([
            $tipo,
            $fields['bairro'] ?? null,
            $fields['cidade'] ?? null,
        ]);

        return mb_substr(implode(' - ', $partes), 0, 255);
    }

    /**
     * Descrição + características sem de/para.
     *
     * Anexar em vez de descartar é deliberado: enquanto o tenant não termina o
     * mapeamento, "LOTEAMENTO: Santa Marta" e "PROXIMIDADE: Cerâmica Wunsch"
     * são exatamente o texto que vende o imóvel.
     */
    private function buildDescription(array $detail, array $listItem, array $leftovers): string
    {
        $base = trim((string) ($detail['descricao'] ?? $listItem['descricaoImovel'] ?? ''));

        if ($leftovers === []) {
            return $base;
        }

        $linhas = array_map(
            static fn (array $l) => $l['label'] . ': ' . $l['valor'],
            $leftovers
        );

        return trim($base . ($base !== '' ? "\n\n" : '') . implode("\n", $linhas));
    }

    /**
     * @return ExternalImage[]
     */
    private function mapImages(array $detail, array $listItem, string $externalId): array
    {
        $raw = $detail['imagens'] ?? $listItem['imagens'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $max    = (int) ($this->settings['max_images'] ?? 20);
        $images = [];

        foreach ($raw as $img) {
            if (! is_array($img)) {
                continue;
            }

            $nome = trim((string) ($img['baseNomeImagem'] ?? ''));
            $ext  = trim((string) ($img['extensao'] ?? ''));

            if ($nome === '' || $ext === '') {
                continue;
            }

            // O detalhe chama de `posicao`, a listagem de `ordem`.
            $ordem = (int) ($img['posicao'] ?? $img['ordem'] ?? count($images) + 1);

            $images[] = new ExternalImage(
                url: SimobClient::imageUrl($this->baseUrl, $externalId, $nome, $ext),
                ordem: $ordem,
                principal: false,
                descricao: $this->str($img['descricao'] ?? null),
            );
        }

        usort($images, static fn (ExternalImage $a, ExternalImage $b) => $a->ordem <=> $b->ordem);

        $images = array_slice($images, 0, max(1, $max));

        // A capa é a primeira depois de ordenar — persistMedia() garante que
        // exista exatamente uma, mas quem escolhe qual é o mapper.
        if ($images !== []) {
            $first     = $images[0];
            $images[0] = new ExternalImage($first->url, $first->ordem, true, $first->descricao);
        }

        return $images;
    }

    // ------------------------------------------------------------- utilidades

    private function str(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Número em formato brasileiro ou americano.
     *
     * O Simob mistura os dois: `configVenda.valor` vem "105000.00" (americano)
     * e características de área vêm "286,65 " (brasileiro, com espaço e às
     * vezes com "m²" colado).
     */
    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        // Tira moeda, unidade e qualquer texto: "R$ 1.260,00" e "286,65 m²".
        $clean = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';

        if ($clean === '' || $clean === '-' || ! preg_match('/\d/', $clean)) {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot   = strrpos($clean, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Vírgula é o separador decimal: 1.260,50
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            // Ponto é o decimal (ou não há decimal): 105000.00 / 1,260
            $clean = str_replace(',', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function cleanDate(mixed $value): ?string
    {
        $value = $this->str($value);

        if ($value === null) {
            return null;
        }

        return strtotime($value) === false ? null : $value;
    }
}
