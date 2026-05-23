<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Busca no Mapa<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="preconnect" href="https://unpkg.com">
<link rel="preconnect" href="https://a.tile.openstreetmap.org">
<link rel="preconnect" href="https://b.tile.openstreetmap.org">
<link rel="preconnect" href="https://c.tile.openstreetmap.org">
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
?>

<div class="map-premium-page" id="mapPremiumPage">
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
                            <select name="bairro" id="filterBairro" class="form-select">
                                <option value="">Todos os bairros</option>
                                <?php if(!empty($bairros)): foreach($bairros as $b): ?>
                                    <option value="<?= esc($b->bairro) ?>" <?= ($filters['bairro'] ?? '') === $b->bairro ? 'selected' : '' ?>><?= esc($b->bairro) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-premium btn-premium-primary" aria-label="Filtrar imóveis">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                </div>

                <div class="map-chip-row" aria-label="Filtros rápidos">
                    <button type="button" class="premium-chip js-business-chip <?= $selectedBusiness === '' ? 'is-active' : '' ?>" data-value="">Todos</button>
                    <button type="button" class="premium-chip js-business-chip <?= $selectedBusiness === 'VENDA' ? 'is-active' : '' ?>" data-value="VENDA">Comprar</button>
                    <button type="button" class="premium-chip js-business-chip <?= $selectedBusiness === 'ALUGUEL' ? 'is-active' : '' ?>" data-value="ALUGUEL">Alugar</button>
                    <button type="button" class="premium-chip" data-bs-toggle="modal" data-bs-target="#advancedFiltersModal">
                        <i class="fa-solid fa-sliders"></i> Filtros
                    </button>
                    <button type="button" class="premium-chip" id="btnClearFilters">
                        <i class="fa-solid fa-rotate-left"></i> Limpar
                    </button>
                </div>

                <input type="hidden" name="tipo_negocio" id="inputTipoNegocio" value="<?= esc($selectedBusiness) ?>">
                <input type="hidden" name="tipo_imovel" id="inputTipoImovel" value="<?= esc($filters['tipo_imovel'] ?? '') ?>">
                <input type="hidden" name="quartos" id="inputQuartos" value="<?= esc($filters['quartos'] ?? '') ?>">
                <input type="hidden" name="banheiros" id="inputBanheiros" value="<?= esc($filters['banheiros'] ?? '') ?>">
                <input type="hidden" name="vagas" id="inputVagas" value="<?= esc($filters['vagas'] ?? '') ?>">
                <input type="hidden" name="min_price" id="inputMinPrice" value="<?= esc($filters['min_price'] ?? '') ?>">
                <input type="hidden" name="max_price" id="inputMaxPrice" value="<?= esc($filters['max_price'] ?? '') ?>">
                <input type="hidden" name="sort" id="inputSort" value="<?= esc($selectedSort) ?>">
                <input type="hidden" name="bounds" id="inputBounds" value="">
                <input type="hidden" name="polygon" id="inputPolygon" value="">
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
        <div class="map-floating-actions">
            <button type="button" class="map-floating-btn" id="btnStartDraw">
                <i class="fa-solid fa-draw-polygon"></i> Desenhar área
            </button>
            <button type="button" class="map-floating-btn text-danger d-none" id="btnClearDraw">
                <i class="fa-solid fa-xmark"></i> Apagar área
            </button>
        </div>
    </section>

    <button type="button" class="btn btn-premium btn-premium-primary map-mobile-toggle" id="btnMobileToggle">
        <i class="fa-solid fa-map"></i> Ver mapa
    </button>
</div>

<div class="modal fade" id="advancedFiltersModal" tabindex="-1" aria-labelledby="advancedFiltersTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
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
                        <input type="number" id="modalMinPrice" class="form-control" value="<?= esc($filters['min_price'] ?? '') ?>" placeholder="R$ 0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted">Preço máximo</label>
                        <input type="number" id="modalMaxPrice" class="form-control" value="<?= esc($filters['max_price'] ?? '') ?>" placeholder="R$ 1.000.000">
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
    const mobileToggle = document.getElementById('btnMobileToggle');

    let activeFetchController = null;
    let fetchTimeout = null;
    let currentPolygon = null;
    let firstLoadDone = false;
    let fittedInitialMarkers = false;
    let suppressNextMoveFetch = false;
    let drawAssetsPromise = null;
    let polygonDrawer = null;

    const map = L.map('map', { zoomControl: false }).setView([-14.235, -51.925], 4);
    L.control.zoom({ position: 'topright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    requestAnimationFrame(() => map.invalidateSize());

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

    const drawnItems = new L.FeatureGroup();
    map.addLayer(drawnItems);
    const drawOptions = {
        allowIntersection: false,
        drawError: { color: '#ef4444', message: '<strong>Ops!</strong> ajuste o desenho para não cruzar linhas.' },
        shapeOptions: { color: '#0f766e', fillOpacity: 0.16, weight: 2 }
    };

    function loadDrawAssets() {
        if (window.L && L.Draw) {
            return Promise.resolve();
        }
        if (drawAssetsPromise) {
            return drawAssetsPromise;
        }

        drawAssetsPromise = new Promise((resolve, reject) => {
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css';
            document.head.appendChild(css);

            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js';
            script.onload = resolve;
            script.onerror = reject;
            document.body.appendChild(script);
        });

        return drawAssetsPromise;
    }

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

    function updateBounds() {
        if (currentPolygon) {
            inputBounds.value = '';
            return;
        }
        const bounds = map.getBounds();
        const sw = bounds.getSouthWest();
        const ne = bounds.getNorthEast();
        inputBounds.value = `${sw.lng},${sw.lat},${ne.lng},${ne.lat}`;
    }

    function syncUrl() {
        const params = new URLSearchParams(new FormData(form));
        ['bounds', 'polygon', 'property_ids', 'page', 'per_page'].forEach(key => params.delete(key));
        for (const [key, value] of Array.from(params.entries())) {
            if (!value) params.delete(key);
        }
        const url = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
        window.history.replaceState({}, '', url);
    }

    function fetchMapData(options = {}) {
        const append = options.append === true;
        const onlyUpdateList = options.onlyUpdateList === true;

        if (activeFetchController) {
            activeFetchController.abort();
        }
        activeFetchController = new AbortController();

        updateBounds();
        if (!append) {
            inputPage.value = '1';
            setSkeleton();
        }
        syncUrl();

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

                resultsTitle.textContent = `${data.count || 0} imóveis encontrados`;
                resultsSubtitle.textContent = currentPolygon ? 'Resultado dentro da área desenhada' : `${data.map_count || 0} pins na área visível`;

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
                html: `<div class="price-marker-pill ${prop.is_sponsored ? 'sponsored' : ''}" id="marker-pill-${prop.id}">R$ ${prop.price}</div>`,
                iconSize: [84, 34],
                iconAnchor: [42, 34]
            });
            const marker = L.marker([prop.lat, prop.lng], { icon, propertyId: prop.id });
            marker.on('click', function() {
                activateProperty(prop.id);
            });
            return marker;
        });
        markers.addLayers(nextMarkers);
        if (!fittedInitialMarkers && items.length && (document.getElementById('filterCidade').value || cidadeParam)) {
            fittedInitialMarkers = true;
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

    function scheduleFetch() {
        clearTimeout(fetchTimeout);
        fetchTimeout = setTimeout(() => {
            inputPropertyIds.value = '';
            fetchMapData();
        }, 360);
    }

    function updateMobileToggle() {
        const showingList = pageShell.classList.contains('show-list');
        mobileToggle.innerHTML = showingList
            ? '<i class="fa-solid fa-map"></i> Ver mapa'
            : '<i class="fa-solid fa-list"></i> Ver lista';
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
            fetchMapData();
            return;
        }
        if (inputPropertyIds.value) inputPropertyIds.value = '';
        scheduleFetch();
    });

    map.on('draw:created', function(event) {
        drawnItems.clearLayers();
        drawnItems.addLayer(event.layer);
        currentPolygon = event.layer;
        inputPropertyIds.value = '';
        const coords = event.layer.getLatLngs()[0].map(point => [point.lng, point.lat]);
        inputPolygon.value = JSON.stringify(coords);
        document.getElementById('btnStartDraw').classList.add('d-none');
        document.getElementById('btnClearDraw').classList.remove('d-none');
        fetchMapData();
    });

    document.getElementById('btnStartDraw').addEventListener('click', () => {
        const button = document.getElementById('btnStartDraw');
        const original = button.innerHTML;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Carregando...';
        loadDrawAssets()
            .then(() => {
                polygonDrawer = polygonDrawer || new L.Draw.Polygon(map, drawOptions);
                polygonDrawer.enable();
                button.innerHTML = original;
            })
            .catch(() => {
                button.innerHTML = original;
                alert('Não foi possível carregar a ferramenta de desenho agora.');
            });
    });
    document.getElementById('btnClearDraw').addEventListener('click', () => {
        drawnItems.clearLayers();
        currentPolygon = null;
        inputPolygon.value = '';
        inputPropertyIds.value = '';
        document.getElementById('btnClearDraw').classList.add('d-none');
        document.getElementById('btnStartDraw').classList.remove('d-none');
        fetchMapData();
    });

    document.querySelectorAll('.js-business-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.js-business-chip').forEach(item => item.classList.remove('is-active'));
            chip.classList.add('is-active');
            document.getElementById('inputTipoNegocio').value = chip.dataset.value;
            inputPropertyIds.value = '';
            fetchMapData();
        });
    });

    $('#mapFiltersForm').on('change', 'select', function() {
        inputPropertyIds.value = '';
        scheduleFetch();
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        inputPropertyIds.value = '';
        fetchMapData();
    });

    document.getElementById('sortSelect').addEventListener('change', function() {
        document.getElementById('inputSort').value = this.value;
        document.getElementById('modalSort').value = this.value;
        fetchMapData();
    });

    document.getElementById('btnApplyAdvancedFilters').addEventListener('click', function() {
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
        fetchMapData();
    });

    document.getElementById('btnClearFilters').addEventListener('click', function() {
        form.reset();
        $('#filterCidade, #filterBairro').val('');
        ['inputTipoNegocio','inputTipoImovel','inputQuartos','inputBanheiros','inputVagas','inputMinPrice','inputMaxPrice','inputSort','inputPropertyIds','inputPolygon'].forEach(id => {
            document.getElementById(id).value = id === 'inputSort' ? 'relevance' : '';
        });
        document.getElementById('sortSelect').value = 'relevance';
        document.querySelectorAll('.js-business-chip').forEach(chip => chip.classList.toggle('is-active', chip.dataset.value === ''));
        drawnItems.clearLayers();
        currentPolygon = null;
        document.getElementById('btnClearDraw').classList.add('d-none');
        document.getElementById('btnStartDraw').classList.remove('d-none');
        fetchMapData();
    });

    listContainer.addEventListener('click', function(event) {
        const button = event.target.closest('#btnLoadMoreMap');
        if (!button) return;
        inputPage.value = button.dataset.nextPage;
        fetchMapData({ append: true, onlyUpdateList: true });
    });

    mobileToggle.addEventListener('click', function() {
        pageShell.classList.toggle('show-list');
        updateMobileToggle();
        setTimeout(() => map.invalidateSize(), 240);
    });

    firstLoadDone = true;
    fetchMapData();

    if (!cidadeParam && 'geolocation' in navigator) {
        navigator.geolocation.getCurrentPosition(
            position => {
                suppressNextMoveFetch = true;
                map.setView([position.coords.latitude, position.coords.longitude], 13);
                setTimeout(() => {
                    suppressNextMoveFetch = false;
                    fetchMapData();
                }, 250);
            },
            () => {},
            { timeout: 1500, maximumAge: 120000 }
        );
    }

    updateMobileToggle();
});
</script>
<?= $this->endSection() ?>
