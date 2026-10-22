<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Busca no Mapa<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="preconnect" href="https://unpkg.com">
<?php
    // Host do provedor configurado. Um template com {s} (subdomínios a/b/c,
    // padrão legado do OSM) não tem host fixo pra pré-conectar — pula.
    $tileHost = parse_url(config('Map')->tileUrl, PHP_URL_HOST);
?>
<?php if ($tileHost && ! str_contains($tileHost, '{')): ?>
<link rel="preconnect" href="https://<?= esc($tileHost, 'attr') ?>" crossorigin>
<?php endif; ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css" />
<style>
    body { overflow: hidden; }
    footer { display: none; }
    main { min-height: 0; }
    .navbar { box-shadow: 0 1px 0 rgba(15, 23, 42, .08) !important; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
    $selectedBusiness = $filters['tipo_negocio'] ?? '';
    $selectedSort = $filters['sort'] ?? 'relevance';
    $selectedCidade = $filters['cidade'] ?? '';
    $selectedBairro = $filters['bairro'] ?? '';
    $bairroNaLista = in_array($selectedBairro, array_map(static fn ($b) => $b->bairro, $bairros ?? []), true);
?>

<?php // show-list: no celular a busca abre na LISTA de imóveis (o mapa fica a um toque); no desktop a classe não tem efeito. ?>
<div class="map-premium-page show-list" id="mapPremiumPage">
    <aside class="map-sidebar" aria-label="Resultados da busca no mapa">
        <div class="map-topbar">
            <form id="mapFiltersForm" class="mb-0">
                <div class="map-search-row">
                    <div class="map-filter-grid">
                        <div class="map-field">
                            <label for="filterCidade">Cidade</label>
                            <select name="cidade" id="filterCidade" class="form-select">
                                <option value="">Todas as cidades</option>
                                <?php if(!empty($cidades)): foreach($cidades as $c): ?>
                                    <option value="<?= esc($c->cidade) ?>" <?= ($filters['cidade'] ?? '') === $c->cidade ? 'selected' : '' ?>><?= esc($c->cidade) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>

                        <div class="map-field">
                            <label for="filterBairro">Bairro</label>
                            <?php // Bairro depende da cidade: sem cidade, fica desabilitado (a não ser que a URL já traga um bairro). ?>
                            <select name="bairro" id="filterBairro" class="form-select" <?= $selectedCidade === '' && $selectedBairro === '' ? 'disabled' : '' ?>>
                                <option value=""><?= $selectedCidade === '' ? 'Escolha a cidade' : 'Todos os bairros' ?></option>
                                <?php if(!empty($bairros)): foreach($bairros as $b): ?>
                                    <option value="<?= esc($b->bairro) ?>" <?= $selectedBairro === $b->bairro ? 'selected' : '' ?>><?= esc($b->bairro) ?></option>
                                <?php endforeach; endif; ?>
                                <?php if($selectedBairro !== '' && ! $bairroNaLista): ?>
                                    <option value="<?= esc($selectedBairro) ?>" selected><?= esc($selectedBairro) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-premium btn-premium-primary" aria-label="Buscar imóveis">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="map-chip-row" aria-label="Filtros rápidos">
                    <button type="button" class="premium-chip js-business-chip <?= $selectedBusiness === '' ? 'is-active' : '' ?>" data-value="">Todos</button>
                    <button type="button" class="premium-chip js-business-chip <?= $selectedBusiness === 'VENDA' ? 'is-active' : '' ?>" data-value="VENDA">Comprar</button>
                    <button type="button" class="premium-chip js-business-chip <?= $selectedBusiness === 'ALUGUEL' ? 'is-active' : '' ?>" data-value="ALUGUEL">Alugar</button>
                    <button type="button" class="premium-chip" id="btnAdvancedFilters" data-bs-toggle="modal" data-bs-target="#advancedFiltersModal" aria-label="Filtros avançados">
                        <i class="fa-solid fa-sliders" aria-hidden="true"></i> Filtros
                        <span class="map-filter-count d-none" id="advancedFilterCount" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="premium-chip" id="btnClearFilters">
                        <i class="fa-solid fa-rotate-left"></i> Limpar
                    </button>
                </div>

                <?php // Critérios ativos que não aparecem na barra (tipo, preço, quartos...), cada um removível. Preenchido pelo JS. ?>
                <div class="map-active-filters d-none" id="activeFilterChips" aria-label="Filtros ativos"></div>

                <input type="hidden" name="tipo_negocio" id="inputTipoNegocio" value="<?= esc($selectedBusiness) ?>">
                <input type="hidden" name="tipo_imovel" id="inputTipoImovel" value="<?= esc($filters['tipo_imovel'] ?? '') ?>">
                <input type="hidden" name="quartos" id="inputQuartos" value="<?= esc($filters['quartos'] ?? '') ?>">
                <input type="hidden" name="banheiros" id="inputBanheiros" value="<?= esc($filters['banheiros'] ?? '') ?>">
                <input type="hidden" name="vagas" id="inputVagas" value="<?= esc($filters['vagas'] ?? '') ?>">
                <input type="hidden" name="min_price" id="inputMinPrice" value="<?= esc($filters['min_price'] ?? '') ?>">
                <input type="hidden" name="max_price" id="inputMaxPrice" value="<?= esc($filters['max_price'] ?? '') ?>">
                <input type="hidden" name="include_no_price" id="inputIncludeNoPrice" value="<?= ! empty($filters['include_no_price']) && filter_var($filters['include_no_price'], FILTER_VALIDATE_BOOLEAN) ? '1' : '' ?>">
                <input type="hidden" name="sort" id="inputSort" value="<?= esc($selectedSort) ?>">
                <input type="hidden" name="bounds" id="inputBounds" value="">
                <input type="hidden" name="polygon" id="inputPolygon" value="<?= esc($filters['polygon'] ?? '') ?>">
                <input type="hidden" name="property_ids" id="inputPropertyIds" value="">
                <input type="hidden" name="page" id="inputPage" value="1">
                <input type="hidden" name="per_page" value="12">
            </form>
        </div>

        <div class="map-results-head">
            <div>
                <h1 class="map-results-title" id="mapResultsTitle">Imóveis no mapa</h1>
                <p class="map-results-subtitle" id="mapResultsSubtitle">Atualizando a área visível...</p>
            </div>
            <select class="form-select form-select-sm" id="sortSelect" style="width: 150px;">
                <option value="relevance" <?= $selectedSort === 'relevance' ? 'selected' : '' ?>>Relevância</option>
                <option value="recent" <?= $selectedSort === 'recent' ? 'selected' : '' ?>>Mais recentes</option>
                <option value="price_asc" <?= $selectedSort === 'price_asc' ? 'selected' : '' ?>>Menor preço</option>
                <option value="price_desc" <?= $selectedSort === 'price_desc' ? 'selected' : '' ?>>Maior preço</option>
            </select>
        </div>

        <div class="map-results-scroll" id="propertyListContainer">
            <div class="map-list-grid" aria-hidden="true">
                <?php for($i = 0; $i < 6; $i++): ?>
                    <div class="skeleton-card">
                        <div class="skeleton-media"></div>
                        <div class="p-3 d-grid gap-2">
                            <div class="skeleton-line" style="width: 75%;"></div>
                            <div class="skeleton-line" style="width: 100%;"></div>
                            <div class="skeleton-line" style="width: 52%;"></div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </aside>

    <section class="map-pane-premium" aria-label="Mapa de imóveis">
        <div id="map"></div>
        <?php // Celular: faixa de cartões sobre o mapa, para o visitante ver os imóveis sem sair do mapa. Preenchida pelo JS a partir da lista. ?>
        <div class="map-card-strip" id="mapCardStrip" aria-label="Imóveis nesta área" hidden></div>
    </section>

    <button type="button" class="btn btn-premium btn-premium-primary map-mobile-toggle" id="btnMobileToggle">
        <i class="fa-solid fa-map"></i> Ver mapa
    </button>
</div>

<div class="modal fade" id="advancedFiltersModal" tabindex="-1" aria-labelledby="advancedFiltersTitle" aria-hidden="true">
    <?php // Rolável com cabeçalho/rodapé fixos e tela cheia no celular: o "Aplicar filtros" ficava abaixo da dobra. ?>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
        <div class="modal-content border-0 shadow-lg map-filter-drawer">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="advancedFiltersTitle">Filtros avançados</h5>
                    <p class="small text-muted mb-0">Refine a busca sem sair do mapa.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Tipo de imóvel</label>
                        <select id="modalTipoImovel" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach($tipos as $t): ?>
                                <option value="<?= esc($t->tipo_imovel) ?>" <?= ($filters['tipo_imovel'] ?? '') === $t->tipo_imovel ? 'selected' : '' ?>><?= esc(ucfirst(strtolower($t->tipo_imovel))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Ordenar por</label>
                        <select id="modalSort" class="form-select">
                            <option value="relevance" <?= $selectedSort === 'relevance' ? 'selected' : '' ?>>Relevância</option>
                            <option value="recent" <?= $selectedSort === 'recent' ? 'selected' : '' ?>>Mais recentes</option>
                            <option value="price_asc" <?= $selectedSort === 'price_asc' ? 'selected' : '' ?>>Menor preço</option>
                            <option value="price_desc" <?= $selectedSort === 'price_desc' ? 'selected' : '' ?>>Maior preço</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Preço mínimo</label>
                        <input type="number" id="modalMinPrice" class="form-control" min="0" step="any" inputmode="decimal" value="<?= esc($filters['min_price'] ?? '') ?>" placeholder="R$ 0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Preço máximo</label>
                        <input type="number" id="modalMaxPrice" class="form-control" min="0" step="any" inputmode="decimal" value="<?= esc($filters['max_price'] ?? '') ?>" placeholder="R$ 1.000.000">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="modalIncludeNoPrice" <?= ! empty($filters['include_no_price']) && filter_var($filters['include_no_price'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="modalIncludeNoPrice">Incluir imóveis sob consulta (sem preço informado)</label>
                        </div>
                        <div id="priceRangeError" class="text-danger small mt-1<?= empty($priceError) ? ' d-none' : '' ?>" role="alert"<?= empty($priceError) ? '' : ' data-server="1"' ?>><?= esc($priceError ?? '') ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">Quartos</label>
                        <select id="modalQuartos" class="form-select">
                            <option value="">Qualquer</option>
                            <?php foreach([1,2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($filters['quartos'] ?? '') == $n ? 'selected' : '' ?>><?= $n ?>+</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">Banheiros</label>
                        <select id="modalBanheiros" class="form-select">
                            <option value="">Qualquer</option>
                            <?php foreach([1,2,3] as $n): ?>
                                <option value="<?= $n ?>" <?= ($filters['banheiros'] ?? '') == $n ? 'selected' : '' ?>><?= $n ?>+</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">Vagas</label>
                        <select id="modalVagas" class="form-select">
                            <option value="">Qualquer</option>
                            <?php foreach([1,2,3] as $n): ?>
                                <option value="<?= $n ?>" <?= ($filters['vagas'] ?? '') == $n ? 'selected' : '' ?>><?= $n ?>+</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light btn-premium" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-premium btn-premium-primary" id="btnApplyAdvancedFilters">
                    <i class="fa-solid fa-check"></i> Aplicar filtros
                </button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js"></script>
<script src="<?= base_url('assets/js/map-draw-toolbar.js') ?>?v=<?= filemtime(FCPATH . 'assets/js/map-draw-toolbar.js') ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const pageShell = document.getElementById('mapPremiumPage');
    const form = document.getElementById('mapFiltersForm');
    const listContainer = document.getElementById('propertyListContainer');
    const resultsTitle = document.getElementById('mapResultsTitle');
    const resultsSubtitle = document.getElementById('mapResultsSubtitle');
    const inputBounds = document.getElementById('inputBounds');
    const inputPolygon = document.getElementById('inputPolygon');
    const inputPropertyIds = document.getElementById('inputPropertyIds');
    const inputPage = document.getElementById('inputPage');
    const cidadeParam = new URLSearchParams(window.location.search).get('cidade');
    const mapApiUrl = new URL('<?= site_url('api/imoveis/mapa') ?>');
    const mapApiPath = mapApiUrl.pathname;
    const bairrosApiUrl = new URL('<?= site_url('api/imoveis/bairros') ?>');
    const bairrosApiPath = bairrosApiUrl.pathname;
    const mobileToggle = document.getElementById('btnMobileToggle');
    const cidadeSelect = document.getElementById('filterCidade');
    const bairroSelect = document.getElementById('filterBairro');
    const activeFilterChips = document.getElementById('activeFilterChips');
    const advancedFilterCount = document.getElementById('advancedFilterCount');
    const advancedFiltersButton = document.getElementById('btnAdvancedFilters');
    const advancedFiltersModal = document.getElementById('advancedFiltersModal');
    // URL que a busca grava ao mudar de filtro. As rotas SEO
    // (/imoveis/venda/sao-paulo) carregam cidade/negócio no CAMINHO, e o
    // caminho vence a querystring no servidor: trocar a cidade e recarregar
    // voltava para a cidade antiga. Saindo do filtro inicial, a URL passa
    // para /imoveis?..., que carrega tudo pela querystring.
    const searchBasePath = new URL('<?= site_url('imoveis') ?>').pathname;
    const plainSearchPaths = [searchBasePath, new URL('<?= site_url('imoveis/mapa') ?>').pathname];
    const byId = id => document.getElementById(id);

    let activeFetchController = null;
    let fetchTimeout = null;
    let currentPolygon = null;
    let firstLoadDone = false;
    let lastFetchUsedViewport = false;
    let lastFetchWasInitial = false;
    let isInitialFetch = true;
    let suppressNextMoveFetch = false;
    let initialQuery = null;

    const map = L.map('map', { zoomControl: false }).setView([-14.235, -51.925], 4);
    L.control.zoom({ position: 'topright', zoomInTitle: 'Aproximar', zoomOutTitle: 'Afastar' }).addTo(map);
    L.tileLayer(<?= json_encode(config('Map')->tileUrl) ?>, {
        maxZoom: <?= (int) config('Map')->tileMaxZoom ?>,
        tileSize: <?= (int) config('Map')->tileSize ?>,
        zoomOffset: <?= (int) config('Map')->tileZoomOffset ?>,
        referrerPolicy: <?= json_encode(config('Map')->tileReferrerPolicy) ?>,
        attribution: <?= json_encode(config('Map')->tileAttribution) ?>

    }).addTo(map);
    requestAnimationFrame(() => map.invalidateSize());

    // Barra de desenho compartilhada com a home (assets/js/map-draw-toolbar.js):
    // Desenhar área -> Concluir / Desfazer / Cancelar -> Apagar área. Continua
    // em bottomleft, que o public.css centraliza na base do mapa (e sobe acima
    // do botão "Ver lista" no celular).
    const drawToolbar = HabitawebMapDraw.attach(map, {
        position: 'bottomleft',
        layout: 'row',
        assetsBase: <?= json_encode(base_url('assets/js/leaflet-draw'), JSON_UNESCAPED_SLASHES) ?>,
        shapeOptions: { color: '#0f766e', fillOpacity: 0.16, weight: 2 },
        drawError: { color: '#ef4444', message: '<strong>Ops!</strong> ajuste o desenho para não cruzar linhas.' },
        onCreated: function(layer, coords) {
            currentPolygon = layer;
            inputPropertyIds.value = '';
            inputPolygon.value = JSON.stringify(coords);
            fetchMapData({ history: 'push' });
        },
        onCleared: function() {
            currentPolygon = null;
            inputPolygon.value = '';
            inputPropertyIds.value = '';
            fetchMapData({ history: 'push' });
        }
    });

    const markers = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 42,
        spiderfyOnMaxZoom: true,
        zoomToBoundsOnClick: false,
        iconCreateFunction: function(cluster) {
            const count = cluster.getChildCount();
            const size = count > 100 ? 58 : (count > 40 ? 50 : 42);
            const sizeClass = count > 100 ? 'xlarge' : (count > 40 ? 'large' : '');
            return L.divIcon({
                html: `<div class="premium-cluster-bubble ${sizeClass}">${count}</div>`,
                className: 'premium-cluster-icon',
                iconSize: L.point(size, size)
            });
        }
    });
    map.addLayer(markers);

    function setSkeleton() {
        listContainer.innerHTML = `
            <div class="map-list-grid" aria-hidden="true">
                ${Array.from({ length: 6 }).map(() => `
                    <div class="skeleton-card">
                        <div class="skeleton-media"></div>
                        <div class="p-3 d-grid gap-2">
                            <div class="skeleton-line" style="width: 75%;"></div>
                            <div class="skeleton-line" style="width: 100%;"></div>
                            <div class="skeleton-line" style="width: 52%;"></div>
                        </div>
                    </div>
                `).join('')}
            </div>`;
    }

    function updateBounds(useViewport) {
        if (!useViewport || currentPolygon) {
            inputBounds.value = '';
            return;
        }
        const bounds = map.getBounds();
        const sw = bounds.getSouthWest();
        const ne = bounds.getNorthEast();
        inputBounds.value = `${sw.lng},${sw.lat},${ne.lng},${ne.lat}`;
    }

    /** Querystring dos critérios da busca (sem viewport, cluster e paginação). */
    function currentQuery() {
        const params = new URLSearchParams(new FormData(form));
        // `polygon` fica: é o deep link vindo do mapa da home, e recarregar ou
        // compartilhar a URL precisa preservar a área desenhada.
        ['bounds', 'property_ids', 'page', 'per_page'].forEach(key => params.delete(key));
        for (const [key, value] of Array.from(params.entries())) {
            if (!value) params.delete(key);
        }
        if (params.get('sort') === 'relevance') params.delete('sort');
        return params.toString();
    }

    /**
     * Grava os critérios na URL. 'push' cria entrada no histórico (mudança de
     * filtro feita pelo usuário: o "voltar" do navegador desfaz), 'replace'
     * só atualiza (mover o mapa, paginação, carga inicial), 'none' não mexe
     * (restauração vinda do próprio histórico). O estado guarda a querystring
     * para o popstate restaurar até a entrada inicial de uma rota SEO, cuja
     * URL não tem querystring nenhuma.
     */
    function syncUrl(mode) {
        if (mode === 'none') return;
        const q = currentQuery();
        let path = window.location.pathname;
        if (!plainSearchPaths.includes(path)) {
            if (q === initialQuery) {
                window.history.replaceState({ q }, '', window.location.href);
                return;
            }
            path = searchBasePath;
        }
        const url = path + (q ? '?' + q : '');
        if (mode === 'push' && url !== window.location.pathname + window.location.search) {
            window.history.pushState({ q }, '', url);
        } else {
            window.history.replaceState({ q }, '', url);
        }
    }

    function formatPriceShort(value) {
        const v = Number(value);
        if (!isFinite(v)) return value;
        const trim = (n, dec) => n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: dec });
        if (v < 10000) return 'R$ ' + trim(v, 0);
        if (v < 1000000 && Math.round(v / 100) / 10 < 1000) return 'R$ ' + trim(v / 1000, 1) + ' mil';
        return 'R$ ' + trim(v / 1000000, 2) + ' mi';
    }

    function plural(n, singular, pluralForm) {
        return Number(n) === 1 ? singular : pluralForm;
    }

    /**
     * Critérios ativos que não aparecem na barra principal. Cada item sabe
     * se limpar; `advanced` conta para o número no botão "Filtros".
     */
    function activeFilterItems() {
        const items = [];
        const tipo = byId('inputTipoImovel').value;
        if (tipo) {
            const opt = Array.from(byId('modalTipoImovel').options).find(o => o.value === tipo);
            items.push({ label: opt ? opt.textContent.trim() : tipo, advanced: true, clear: () => { byId('inputTipoImovel').value = ''; } });
        }
        const min = byId('inputMinPrice').value;
        const max = byId('inputMaxPrice').value;
        if (min || max) {
            let label = min && max
                ? `${formatPriceShort(min)} a ${formatPriceShort(max)}`
                : (max ? `Até ${formatPriceShort(max)}` : `A partir de ${formatPriceShort(min)}`);
            if (byId('inputIncludeNoPrice').value === '1') label += ' + sob consulta';
            items.push({ label, advanced: true, clear: () => {
                byId('inputMinPrice').value = '';
                byId('inputMaxPrice').value = '';
                byId('inputIncludeNoPrice').value = '';
            } });
        }
        [['inputQuartos', 'quarto', 'quartos'], ['inputBanheiros', 'banheiro', 'banheiros'], ['inputVagas', 'vaga', 'vagas']].forEach(([id, singular, pluralForm]) => {
            const n = byId(id).value;
            if (n) items.push({ label: `${n}+ ${plural(n, singular, pluralForm)}`, advanced: true, clear: () => { byId(id).value = ''; } });
        });
        if (inputPolygon.value) {
            items.push({ label: 'Área desenhada', advanced: false, clear: () => {
                drawToolbar.clear({ silent: true });
                currentPolygon = null;
                inputPolygon.value = '';
            } });
        }
        return items;
    }

    function renderActiveFilters() {
        const items = activeFilterItems();
        activeFilterChips.replaceChildren();
        items.forEach(item => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'premium-chip is-active';
            chip.setAttribute('aria-label', `Remover filtro ${item.label}`);
            chip.append(document.createTextNode(item.label + ' '));
            const x = document.createElement('i');
            x.className = 'fa-solid fa-xmark';
            x.setAttribute('aria-hidden', 'true');
            chip.append(x);
            chip.addEventListener('click', () => {
                item.clear();
                inputPropertyIds.value = '';
                fetchMapData({ history: 'push' });
            });
            activeFilterChips.append(chip);
        });
        activeFilterChips.classList.toggle('d-none', items.length === 0);

        const advanced = items.filter(item => item.advanced).length;
        advancedFilterCount.textContent = String(advanced);
        advancedFilterCount.classList.toggle('d-none', advanced === 0);
        advancedFiltersButton.classList.toggle('is-active', advanced > 0);
        advancedFiltersButton.setAttribute('aria-label', advanced
            ? `Filtros avançados (${advanced} ${plural(advanced, 'ativo', 'ativos')})`
            : 'Filtros avançados');
    }

    /**
     * Repopula o bairro conforme a cidade. Sem cidade, o campo fica
     * desabilitado: listar bairros de todas as cidades misturava "Centro" de
     * municípios diferentes. Devolve promise para quem precisa do bairro já
     * selecionado antes de buscar (restauração pelo histórico).
     */
    function loadBairros(cidade, selected = '') {
        bairroSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = cidade ? 'Todos os bairros' : 'Escolha a cidade';
        bairroSelect.append(placeholder);
        bairroSelect.disabled = !cidade;
        if (!cidade) return Promise.resolve();

        return fetch(bairrosApiPath + '?cidade=' + encodeURIComponent(cidade))
            .then(response => response.json())
            .then(data => {
                (data.bairros || []).forEach(function(bairro) {
                    const opt = document.createElement('option');
                    opt.value = bairro;
                    opt.textContent = bairro;
                    bairroSelect.appendChild(opt);
                });
            })
            .catch(() => {})
            .then(() => {
                if (!selected) return;
                bairroSelect.value = selected;
                if (bairroSelect.value !== selected) {
                    const opt = document.createElement('option');
                    opt.value = selected;
                    opt.textContent = selected;
                    bairroSelect.appendChild(opt);
                    bairroSelect.value = selected;
                }
            });
    }

    /** Aplica uma querystring (vinda do histórico) aos campos da busca. */
    function applyQuery(params) {
        const get = key => params.get(key) || '';
        byId('inputTipoNegocio').value = get('tipo_negocio');
        document.querySelectorAll('.js-business-chip').forEach(chip => chip.classList.toggle('is-active', chip.dataset.value === get('tipo_negocio')));
        ['tipo_imovel:inputTipoImovel', 'quartos:inputQuartos', 'banheiros:inputBanheiros', 'vagas:inputVagas', 'min_price:inputMinPrice', 'max_price:inputMaxPrice'].forEach(pair => {
            const [key, id] = pair.split(':');
            byId(id).value = get(key);
        });
        byId('inputIncludeNoPrice').value = get('include_no_price') ? '1' : '';
        const sort = get('sort') || 'relevance';
        byId('inputSort').value = sort;
        byId('sortSelect').value = sort;
        inputPropertyIds.value = '';

        const polygon = get('polygon');
        if (polygon !== inputPolygon.value) {
            drawToolbar.clear({ silent: true });
            currentPolygon = null;
            inputPolygon.value = '';
            let coords = null;
            try { coords = polygon ? JSON.parse(polygon) : null; } catch (e) { coords = null; }
            const layer = Array.isArray(coords) ? drawToolbar.setPolygon(coords) : null;
            if (layer) {
                currentPolygon = layer;
                inputPolygon.value = polygon;
            }
        }

        cidadeSelect.value = get('cidade');
        return loadBairros(cidadeSelect.value, get('bairro'));
    }

    function fetchMapData(options = {}) {
        const append = options.append === true;
        const onlyUpdateList = options.onlyUpdateList === true;
        // Só aplica o bounds do viewport quando o próprio mapa foi movido (pan/zoom);
        // troca de filtro de texto (cidade/tipo/preço) não deve ficar presa à área visível atual.
        const useViewportBounds = options.viewportBounds === true;

        if (activeFetchController) {
            activeFetchController.abort();
        }
        activeFetchController = new AbortController();

        lastFetchUsedViewport = useViewportBounds;
        lastFetchWasInitial = isInitialFetch;
        isInitialFetch = false;
        updateBounds(useViewportBounds);
        if (!append) {
            inputPage.value = '1';
            setSkeleton();
        }
        renderActiveFilters();
        syncUrl(options.history || 'replace');

        const params = new URLSearchParams(new FormData(form));
        if (onlyUpdateList) params.set('list_only', '1');

        fetch(mapApiPath + '?' + params.toString(), {
            signal: activeFetchController.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(res => res.json())
            .then(data => {
                activeFetchController = null;
                if (!data.success) return;

                const total = data.count || 0;
                const pins = data.map_count || 0;
                if (!onlyUpdateList || !inputPropertyIds.value) {
                    lastTotal = total;
                    updateMobileToggle();
                }
                resultsTitle.textContent = `${total} ${plural(total, 'imóvel encontrado', 'imóveis encontrados')}`;
                resultsSubtitle.textContent = currentPolygon
                    ? 'Resultado dentro da área desenhada'
                    : `${pins} ${plural(pins, 'imóvel', 'imóveis')} no mapa`;

                if (append) {
                    const loadMore = listContainer.querySelector('#btnLoadMoreMap')?.closest('.d-flex');
                    if (loadMore) loadMore.remove();
                    listContainer.insertAdjacentHTML('beforeend', data.list_html);
                } else {
                    listContainer.innerHTML = data.list_html;
                }

                if (!onlyUpdateList) {
                    renderMarkers(data.map_data || []);
                }
                setupListSync();
                renderCardStrip();
            })
            .catch(err => {
                if (err.name === 'AbortError') return;
                activeFetchController = null;
                console.error(err);
                resultsSubtitle.textContent = 'Não foi possível atualizar agora. Tente novamente.';
            });
    }

    function renderMarkers(items) {
        markers.clearLayers();
        const nextMarkers = items.map(prop => {
            const icon = L.divIcon({
                className: 'custom-pin',
                html: `<div class="price-marker-pill ${prop.is_sponsored ? 'sponsored' : ''}" id="marker-pill-${prop.id}">${prop.price}</div>`,
                iconSize: [84, 34],
                iconAnchor: [42, 34]
            });
            const marker = L.marker([prop.lat, prop.lng], { icon, propertyId: prop.id });
            marker.on('click', function() {
                if (isMobileMapMode()) {
                    showPinCard(prop.id);
                } else {
                    activateProperty(prop.id);
                }
            });
            return marker;
        });
        markers.addLayers(nextMarkers);
        // Resultado veio de um filtro de texto/negócio (não de pan/zoom, área desenhada, ou
        // a carga inicial sem filtro): reenquadra o mapa nos imóveis encontrados em vez de
        // deixar o viewport antigo/desalinhado.
        if (!currentPolygon && !lastFetchUsedViewport && !lastFetchWasInitial && items.length) {
            const markerBounds = L.latLngBounds(items.map(item => [item.lat, item.lng]));
            if (markerBounds.isValid()) {
                suppressNextMoveFetch = true;
                map.fitBounds(markerBounds.pad(0.18), { maxZoom: 13, animate: false });
                setTimeout(() => { suppressNextMoveFetch = false; }, 500);
            }
        }
    }

    function activateProperty(id) {
        document.querySelectorAll('.price-marker-pill').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.map-property-card').forEach(el => el.classList.remove('is-highlighted'));

        const markerEl = document.getElementById(`marker-pill-${id}`);
        if (markerEl) {
            markerEl.classList.add('active');
            const icon = markerEl.closest('.leaflet-marker-icon');
            if (icon) icon.style.zIndex = 1000;
        }

        const card = document.getElementById(`property-card-${id}`);
        if (card) {
            card.classList.add('is-highlighted');
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            pageShell.classList.add('show-list');
            updateMobileToggle();
        }
    }

    function setupListSync() {
        document.querySelectorAll('.map-property-card').forEach(card => {
            const id = card.dataset.id;
            card.addEventListener('mouseenter', () => document.getElementById(`marker-pill-${id}`)?.classList.add('active'));
            card.addEventListener('mouseleave', () => document.getElementById(`marker-pill-${id}`)?.classList.remove('active'));
        });
    }

    function scheduleFetch(options = {}) {
        clearTimeout(fetchTimeout);
        fetchTimeout = setTimeout(() => {
            inputPropertyIds.value = '';
            fetchMapData(options);
        }, 360);
    }

    let lastTotal = null;

    // ---- Faixa de cartões sobre o mapa (celular) ----
    const cardStrip = byId('mapCardStrip');
    const isMobileLayout = () => window.matchMedia('(max-width: 991px)').matches;
    const isMobileMapMode = () => isMobileLayout() && !pageShell.classList.contains('show-list');

    function highlightPin(id) {
        document.querySelectorAll('.price-marker-pill.active').forEach(el => el.classList.remove('active'));
        const pill = document.getElementById(`marker-pill-${id}`);
        if (pill) {
            pill.classList.add('active');
            const icon = pill.closest('.leaflet-marker-icon');
            if (icon) icon.style.zIndex = 1000;
        }
    }

    const stripObserver = 'IntersectionObserver' in window
        ? new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting && entry.target.dataset.id) highlightPin(entry.target.dataset.id);
            });
        }, { root: cardStrip, threshold: 0.6 })
        : null;

    // Cartão compacto montado a partir do cartão da lista (mesmos dados já
    // escapados pelo servidor): foto, preço, bairro e características.
    function buildStripCard(card) {
        const id = card.dataset.id;
        const link = card.querySelector('a.premium-property-link');
        const a = document.createElement('a');
        a.className = 'map-strip-card';
        a.href = link ? link.href : '#';
        a.id = `strip-card-${id}`;
        a.dataset.id = id;

        const foto = card.querySelector('.carousel-item.active img') || card.querySelector('.carousel-item img');
        const img = document.createElement('img');
        img.src = foto ? foto.getAttribute('src') : '';
        img.alt = '';
        img.loading = 'lazy';
        a.append(img);

        const body = document.createElement('div');
        body.className = 'map-strip-body';
        const preco = document.createElement('strong');
        preco.className = 'map-strip-price';
        preco.textContent = (card.querySelector('.premium-price')?.textContent || '').trim();
        const local = document.createElement('span');
        local.className = 'map-strip-location';
        local.textContent = (card.querySelector('.premium-location')?.textContent || '').trim();
        body.append(preco, local);
        const specs = card.querySelector('.premium-specs');
        if (specs && specs.children.length) {
            const clone = specs.cloneNode(true);
            clone.className = 'map-strip-specs';
            body.append(clone);
        }
        a.append(body);
        a.addEventListener('focus', () => highlightPin(id));
        return a;
    }

    function setStripVisible(visible) {
        cardStrip.hidden = !visible;
        pageShell.classList.toggle('has-strip', visible);
    }

    function renderCardStrip() {
        if (stripObserver) stripObserver.disconnect();
        cardStrip.replaceChildren();
        const cards = Array.from(listContainer.querySelectorAll('.map-property-card'));
        cards.forEach(card => {
            const el = buildStripCard(card);
            cardStrip.append(el);
            if (stripObserver) stripObserver.observe(el);
        });
        if (cards.length && lastTotal !== null && lastTotal > cards.length) {
            const mais = document.createElement('button');
            mais.type = 'button';
            mais.className = 'map-strip-more';
            mais.innerHTML = `<i class="fa-solid fa-list" aria-hidden="true"></i><span>Ver todos os ${lastTotal} na lista</span>`;
            mais.addEventListener('click', () => mobileToggle.click());
            cardStrip.append(mais);
        }
        cardStrip.scrollLeft = 0;
        setStripVisible(cards.length > 0);
    }

    // Toque num pin no celular: mostra o cartão daquele imóvel na faixa
    // (buscando-o se não estiver na página carregada) em vez de trocar de tela.
    function showPinCard(id) {
        const existing = document.getElementById(`strip-card-${id}`);
        highlightPin(id);
        if (existing) {
            existing.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
            return;
        }
        fetch(`${mapApiPath}?list_only=1&per_page=6&property_ids=${encodeURIComponent(id)}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => res.json())
            .then(data => {
                const tmp = document.createElement('div');
                tmp.innerHTML = data.list_html || '';
                const card = tmp.querySelector('.map-property-card');
                if (!card) return;
                const el = buildStripCard(card);
                cardStrip.prepend(el);
                if (stripObserver) stripObserver.observe(el);
                setStripVisible(true);
                cardStrip.scrollTo({ left: 0, behavior: 'smooth' });
            })
            .catch(() => {});
    }

    function updateMobileToggle() {
        const showingList = pageShell.classList.contains('show-list');
        mobileToggle.innerHTML = showingList
            ? '<i class="fa-solid fa-map"></i> Ver mapa'
            : '<i class="fa-solid fa-list"></i> Lista' + (lastTotal !== null ? ` (${lastTotal})` : '');
    }

    markers.on('clusterclick', function(event) {
        const ids = event.layer.getAllChildMarkers().map(marker => marker.options.propertyId);
        if (!ids.length) return;
        inputPropertyIds.value = ids.join(',');
        inputPage.value = '1';
        fetchMapData({ onlyUpdateList: true });
        event.layer.zoomToBounds();
        setTimeout(() => {
            if (map.getZoom() >= 16) event.layer.spiderfy();
        }, 220);
    });

    map.on('moveend', function() {
        if (suppressNextMoveFetch) {
            suppressNextMoveFetch = false;
            return;
        }
        if (!firstLoadDone) {
            firstLoadDone = true;
            fetchMapData({ viewportBounds: true });
            return;
        }
        if (inputPropertyIds.value) inputPropertyIds.value = '';
        scheduleFetch({ viewportBounds: true });
    });

    document.querySelectorAll('.js-business-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.js-business-chip').forEach(item => item.classList.remove('is-active'));
            chip.classList.add('is-active');
            document.getElementById('inputTipoNegocio').value = chip.dataset.value;
            inputPropertyIds.value = '';
            fetchMapData({ history: 'push' });
        });
    });

    $('#mapFiltersForm').on('change', 'select', function() {
        inputPropertyIds.value = '';
        scheduleFetch({ history: 'push' });
    });

    // Cascata cidade -> bairro: sem isto o dropdown de bairro listava bairro
    // de qualquer cidade do sistema, e escolher um bairro de outra cidade
    // devolvia "0 imóveis" (combinação que genuinamente não existe, mas
    // parecia busca quebrada). O reset do bairro é síncrono, antes do fetch
    // de repopulação — o handler de 'change' delegado acima já dispara a
    // busca com o bairro limpo, sem precisar esperar a lista nova chegar.
    cidadeSelect.addEventListener('change', function() {
        loadBairros(this.value);
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        inputPropertyIds.value = '';
        fetchMapData({ history: 'push' });
    });

    document.getElementById('sortSelect').addEventListener('change', function() {
        document.getElementById('inputSort').value = this.value;
        document.getElementById('modalSort').value = this.value;
        fetchMapData({ history: 'push' });
    });

    document.getElementById('btnApplyAdvancedFilters').addEventListener('click', function() {
        const priceErrorBox = document.getElementById('priceRangeError');
        const minRaw = document.getElementById('modalMinPrice').value.trim();
        const maxRaw = document.getElementById('modalMaxPrice').value.trim();
        const minVal = minRaw === '' ? null : Number(minRaw);
        const maxVal = maxRaw === '' ? null : Number(maxRaw);
        let priceError = '';
        if ((minVal !== null && (isNaN(minVal) || minVal < 0)) || (maxVal !== null && (isNaN(maxVal) || maxVal < 0))) {
            priceError = 'Informe valores de preço válidos.';
        } else if (minVal !== null && maxVal !== null && minVal > maxVal) {
            priceError = 'O preço mínimo não pode ser maior que o máximo.';
        }
        priceErrorBox.textContent = priceError;
        priceErrorBox.classList.toggle('d-none', priceError === '');
        if (priceError) return;
        document.getElementById('inputIncludeNoPrice').value = document.getElementById('modalIncludeNoPrice').checked ? '1' : '';
        document.getElementById('inputTipoImovel').value = document.getElementById('modalTipoImovel').value;
        document.getElementById('inputQuartos').value = document.getElementById('modalQuartos').value;
        document.getElementById('inputBanheiros').value = document.getElementById('modalBanheiros').value;
        document.getElementById('inputVagas').value = document.getElementById('modalVagas').value;
        document.getElementById('inputMinPrice').value = document.getElementById('modalMinPrice').value;
        document.getElementById('inputMaxPrice').value = document.getElementById('modalMaxPrice').value;
        document.getElementById('inputSort').value = document.getElementById('modalSort').value;
        document.getElementById('sortSelect').value = document.getElementById('modalSort').value;
        inputPropertyIds.value = '';
        bootstrap.Modal.getInstance(document.getElementById('advancedFiltersModal')).hide();
        fetchMapData({ history: 'push' });
    });

    // O modal sempre abre com o estado ATUAL da busca: depois de remover uma
    // etiqueta, voltar no navegador ou cancelar uma edição, os campos dele
    // ficavam com valores que não estavam mais aplicados.
    advancedFiltersModal.addEventListener('show.bs.modal', function() {
        byId('modalTipoImovel').value = byId('inputTipoImovel').value;
        byId('modalSort').value = byId('inputSort').value || 'relevance';
        byId('modalMinPrice').value = byId('inputMinPrice').value;
        byId('modalMaxPrice').value = byId('inputMaxPrice').value;
        byId('modalIncludeNoPrice').checked = byId('inputIncludeNoPrice').value === '1';
        byId('modalQuartos').value = byId('inputQuartos').value;
        byId('modalBanheiros').value = byId('inputBanheiros').value;
        byId('modalVagas').value = byId('inputVagas').value;
        const priceErrorBox = byId('priceRangeError');
        if (!priceErrorBox.dataset.server) {
            priceErrorBox.textContent = '';
            priceErrorBox.classList.add('d-none');
        }
        delete priceErrorBox.dataset.server;
    });

    document.getElementById('btnClearFilters').addEventListener('click', function() {
        form.reset();
        cidadeSelect.value = '';
        loadBairros('');
        ['inputTipoNegocio','inputTipoImovel','inputQuartos','inputBanheiros','inputVagas','inputMinPrice','inputMaxPrice','inputIncludeNoPrice','inputSort','inputPropertyIds','inputPolygon'].forEach(id => {
            document.getElementById(id).value = id === 'inputSort' ? 'relevance' : '';
        });
        document.getElementById('sortSelect').value = 'relevance';
        ['modalMinPrice','modalMaxPrice'].forEach(id => { document.getElementById(id).value = ''; });
        document.getElementById('modalIncludeNoPrice').checked = false;
        document.querySelectorAll('.js-business-chip').forEach(chip => chip.classList.toggle('is-active', chip.dataset.value === ''));
        drawToolbar.clear({ silent: true });
        currentPolygon = null;
        inputPropertyIds.value = '';
        fetchMapData({ history: 'push' });
    });

    // "Voltar"/"avançar" do navegador: restaura os critérios daquela entrada
    // (cidade, bairro, filtros, área) e refaz a busca sem criar entrada nova.
    window.addEventListener('popstate', function(event) {
        const q = event.state && typeof event.state.q === 'string'
            ? event.state.q
            : window.location.search.replace(/^\?/, '');
        applyQuery(new URLSearchParams(q)).then(() => fetchMapData({ history: 'none' }));
    });

    listContainer.addEventListener('click', function(event) {
        const button = event.target.closest('#btnLoadMoreMap');
        if (!button) return;
        inputPage.value = button.dataset.nextPage;
        fetchMapData({ append: true, onlyUpdateList: true });
    });

    // A busca abre no mapa do Brasil inteiro (sem reenquadrar na carga
    // inicial). No celular, ao abrir o mapa pela primeira vez, enquadra nos
    // imóveis encontrados — sem isso o visitante via um continente vazio.
    let mapOpenedOnce = false;

    mobileToggle.addEventListener('click', function() {
        pageShell.classList.toggle('show-list');
        updateMobileToggle();
        setTimeout(() => {
            map.invalidateSize();
            if (!pageShell.classList.contains('show-list') && !mapOpenedOnce) {
                mapOpenedOnce = true;
                const layers = markers.getLayers();
                if (!currentPolygon && layers.length) {
                    const bounds = L.latLngBounds(layers.map(m => m.getLatLng()));
                    if (bounds.isValid()) {
                        suppressNextMoveFetch = true;
                        map.fitBounds(bounds.pad(0.18), { maxZoom: 13, animate: false });
                        setTimeout(() => { suppressNextMoveFetch = false; }, 500);
                    }
                }
            }
            renderCardStrip();
        }, 240);
    });

    // Deep link (mapa da home ou URL compartilhada): a área já chega no input
    // oculto, normalizada pelo servidor. Desenha o polígono, enquadra o mapa
    // nela e deixa o fetch inicial logo abaixo buscar pela área — updateBounds()
    // ignora o viewport quando há polígono. Sem animação o moveend dispara
    // síncrono e consome suppressNextMoveFetch, como no reenquadramento de
    // renderMarkers().
    (function restorePolygonFromUrl() {
        if (!inputPolygon.value) return;
        let coords = null;
        try { coords = JSON.parse(inputPolygon.value); } catch (e) { coords = null; }
        const layer = Array.isArray(coords) ? drawToolbar.setPolygon(coords) : null;
        if (!layer) {
            inputPolygon.value = '';
            return;
        }
        currentPolygon = layer;
        suppressNextMoveFetch = true;
        map.fitBounds(layer.getBounds().pad(0.15), { animate: false });
        setTimeout(() => { suppressNextMoveFetch = false; }, 500);
    })();

    initialQuery = currentQuery();
    firstLoadDone = true;
    fetchMapData();

    // Faixa de preço inválida vinda da URL: o servidor ignorou a faixa; abre o
    // modal para a mensagem aparecer em vez de ficar escondida nele.
    if (byId('priceRangeError').dataset.server) {
        bootstrap.Modal.getOrCreateInstance(advancedFiltersModal).show();
    }

    // Com área vinda da URL o mapa já está enquadrado nela; recentrar na
    // posição do visitante o tiraria de lá.
    if (!cidadeParam && !currentPolygon && 'geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            position => {
                suppressNextMoveFetch = true;
                map.setView([position.coords.latitude, position.coords.longitude], 13);
                setTimeout(() => {
                    suppressNextMoveFetch = false;
                    fetchMapData({ viewportBounds: true });
                }, 250);
            },
            () => {},
            { timeout: 8000, maximumAge: 120000 }
        );
    }

    updateMobileToggle();
});
</script>
<?= $this->endSection() ?>
