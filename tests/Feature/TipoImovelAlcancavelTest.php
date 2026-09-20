<?php
namespace Tests\Feature;
use App\Models\PropertyModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;
/**
 * Invariante do dropdown de busca: TODA opcao oferecida tem de alcancar os
 * imoveis dela, e nenhum imovel publicado pode ficar sem uma opcao que o
 * encontre.
 *
 * Isto existe porque o conserto da cidade duplicada quebrou exatamente isso em
 * tipo_imovel: o dedup por caixa foi aplicado aos tres campos do filtro, mas so
 * cidade e bairro passaram a ser comparados por LOWER(). tipo_imovel continuou
 * em igualdade exata, entao colapsar 'CASA' e 'Casa' numa opcao so fazia os
 * imoveis gravados em caixa alta sumirem do portal — o mesmo bug que a
 * mudanca pretendia consertar, so que noutro campo.
 */
final class TipoImovelAlcancavelTest extends HabitawebTestCase
{
    private int $accountId;
    private PropertyService $service;
    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (new TenantFactory())->create()['account']->id;
        $this->service   = new PropertyService();
        cache()->delete('search_filter_options');
    }
    private function criar(string $tipo): int
    {
        return (int) (new PropertyModel())->insert([
            'account_id'=>$this->accountId,'titulo'=>'Imovel '.bin2hex(random_bytes(3)),
            'tipo_negocio'=>'VENDA','tipo_imovel'=>$tipo,'cidade'=>'Chapecó','bairro'=>'Centro',
            'estado'=>'SC','status'=>'ACTIVE','preco'=>300000,
        ], true);
    }
    /**
     * As duas grafias convivem de verdade: o formulario do admin grava 'CASA',
     * o import de parceiro aceita {"type":"Casa"} e normalizeItem nao canoniza
     * esse campo.
     */
    public function testTodaOpcaoDoDropdownAlcancaSeusImoveis(): void
    {
        $alta = $this->criar('CASA');
        $mista = $this->criar('Casa');
        $tipos = array_map(fn($t)=>$t->tipo_imovel, $this->service->getSearchFilterOptions()['tipos']);
        $alcancaveis = [];
        foreach ($tipos as $t) {
            foreach ($this->service->searchMapList(['tipo_imovel'=>$t])['properties'] as $p) {
                $alcancaveis[(int) $p->id] = true;
            }
        }
        $this->assertArrayHasKey($mista, $alcancaveis);
        $this->assertArrayHasKey($alta, $alcancaveis,
            'imovel gravado como CASA ficou inalcancavel por qualquer opcao do dropdown: '.json_encode($tipos));
    }
}
