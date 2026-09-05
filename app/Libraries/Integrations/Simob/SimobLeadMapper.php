<?php

namespace App\Libraries\Integrations\Simob;

/**
 * Lead do Habitaweb -> interesse do SimobCRM.
 *
 * O endpoint /crm_interesse/create é a única via de VOLTA da integração: a API
 * do Simob não deixa criar nem alterar imóvel, mas aceita interesse. Quando a
 * imobiliária não tem o módulo CRM contratado, o Simob manda um e-mail em vez
 * de criar o registro — e é o objeto `config` que diz para onde.
 *
 * Campos obrigatórios do lado de lá:
 *   - `nome` mais pelo menos um entre `email` e `telefone`;
 *   - `categoria`: array de ids de categoria (a doc marca como obrigatório).
 */
class SimobLeadMapper
{
    /**
     * @param array<string, mixed> $lead     nome, email, telefone, mensagem, url do imóvel
     * @param array<string, mixed> $property external_id, categoria externa, endereço, tipo_negocio
     *
     * @return array<string, mixed> Um interesse (o client embrulha num array)
     */
    public function map(array $lead, array $property, array $options = []): array
    {
        $telefone = $this->digitsOrNull($lead['telefone'] ?? null);
        $email    = $this->str($lead['email'] ?? null);

        $interesse = [
            'nome'         => mb_substr($this->str($lead['nome'] ?? null) ?? 'Interessado', 0, 120),
            'email'        => $email ?? '',
            'telefone'     => $this->str($lead['telefone'] ?? null) ?? '',
            'observacao'   => $this->buildObservacao($lead, $property),
            'finalidade'   => $this->finalidade($property['tipo_negocio'] ?? null),
            'classificacao' => 0,
            'prospeccao'   => null,
            // Busca inteligente do lado do Simob: ele procura uma fonte de
            // prospecção com nome parecido e usa a mais compatível.
            'origem'       => 'Habitaweb',
        ];

        if ($telefone !== null) {
            // O formato exigido é [{id, tel}]; o id é sequencial dentro do
            // próprio array, não um id do sistema.
            $interesse['telefones'] = [['id' => 1, 'tel' => (string) ($lead['telefone'] ?? '')]];
        }

        $categoriaExterna = $this->str($property['external_categoria_id'] ?? null);
        $interesse['categoria'] = $categoriaExterna !== null ? [(int) $categoriaExterna] : [];

        $externalId = $this->str($property['external_id'] ?? null);

        if ($externalId !== null) {
            // Marca o imóvel como favorito do interesse — é o que liga o lead
            // ao anúncio dentro do CRM da imobiliária.
            $interesse['idsImovel'] = [(int) $externalId];
        }

        $endereco = $this->buildEndereco($property);

        if ($endereco !== null) {
            $interesse['enderecos'] = [$endereco];
        }

        $interesse['config'] = array_filter([
            'urlImovel' => $this->str($lead['url_imovel'] ?? null),
            'emailImob' => $this->str($options['email_imobiliaria'] ?? null),
            'tituloMsg' => 'Novo contato pelo Habitaweb',
        ], static fn ($v) => $v !== null);

        return $interesse;
    }

    /**
     * tipo_negocio do Habitaweb -> finalidade do Simob.
     *
     * 1 = locação, 2 = vendas. VENDA_ALUGUEL cai em vendas: é preciso escolher
     * uma, e é a que o corretor prioriza no atendimento.
     */
    private function finalidade(?string $tipoNegocio): int
    {
        return match (strtoupper((string) $tipoNegocio)) {
            'ALUGUEL', 'TEMPORADA' => SimobClient::FINALIDADE_LOCACAO,
            default                => SimobClient::FINALIDADE_VENDA,
        };
    }

    private function buildObservacao(array $lead, array $property): string
    {
        $partes = [];

        $mensagem = $this->str($lead['mensagem'] ?? null);

        if ($mensagem !== null) {
            $partes[] = $mensagem;
        }

        $codigo = $this->str($property['external_code'] ?? $property['external_id'] ?? null);

        if ($codigo !== null) {
            $partes[] = "Imóvel {$codigo}.";
        }

        $partes[] = 'Lead recebido pelo portal Habitaweb.';

        return mb_substr(implode(' ', $partes), 0, 1000);
    }

    /** @return array<string, mixed>|null */
    private function buildEndereco(array $property): ?array
    {
        $uf     = $this->str($property['estado'] ?? null);
        $cidade = $this->str($property['cidade'] ?? null);
        $bairro = $this->str($property['bairro'] ?? null);

        if ($uf === null && $cidade === null && $bairro === null) {
            return null;
        }

        $endereco = array_filter([
            'uf'     => $uf === null ? null : mb_strtolower($uf),
            'cidade' => $cidade,
        ], static fn ($v) => $v !== null);

        if ($bairro !== null) {
            // Sem id, só o nome: a doc diz que o Simob procura o bairro
            // compatível quando recebe apenas a descrição.
            $endereco['bairro'] = [['id' => '', 'nome' => $bairro]];
        }

        return $endereco;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function digitsOrNull(mixed $value): ?string
    {
        $value = $this->str($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }
}
