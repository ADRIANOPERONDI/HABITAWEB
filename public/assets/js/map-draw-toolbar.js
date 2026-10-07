/*
 * Barra de desenho de área (perímetro) para os mapas Leaflet do portal.
 *
 * Carrega o Leaflet.draw local sob demanda e monta um controle com três
 * estados: ocioso ("Desenhar área"), desenhando ("Concluir", "Desfazer",
 * "Cancelar") e desenhado ("Apagar área").
 *
 * "Concluir" e "Cancelar" existem por causa do celular: fechar o polígono do
 * jeito nativo exige tocar a 10px do primeiro vértice, e cancelar exige Esc.
 * O handler de toque do próprio Leaflet.draw nem chega a rodar aqui — o init
 * hook L.Map.TouchExtend só é registrado quando o script carrega, e nesse
 * momento o mapa já foi criado — então os toques viram cliques de
 * compatibilidade do navegador e os botões precisam fechar o ciclo.
 *
 * Uso:
 *   const toolbar = HabitawebMapDraw.attach(map, {
 *       assetsBase: '/assets/js/leaflet-draw',
 *       position: 'topright',                 // qualquer canto do Leaflet
 *       layout: 'column',                     // ou 'row' (botões lado a lado)
 *       onCreated(layer, coords) { ... },     // coords = [[lng, lat], ...]
 *       onCleared() { ... }
 *   });
 *   toolbar.setPolygon(coords); toolbar.clear({ silent: true }); toolbar.hasPolygon();
 */
(function (global) {
    'use strict';

    var MIN_VERTICES = 3;

    var DEFAULT_LABELS = {
        start: 'Desenhar área',
        finish: 'Concluir',
        undo: 'Desfazer',
        cancel: 'Cancelar',
        clear: 'Apagar área',
        loading: 'Carregando...',
        loadError: 'Não foi possível carregar a ferramenta de desenho agora.'
    };

    var DEFAULT_SHAPE = { color: '#0f766e', fillOpacity: 0.16, weight: 2 };
    var DEFAULT_ERROR = { color: '#ef4444', message: '<strong>Ops!</strong> ajuste o desenho para não cruzar linhas.' };

    var assetsPromise = null;

    function loadAssets(assetsBase) {
        if (global.L && global.L.Draw) {
            return Promise.resolve();
        }
        if (assetsPromise) {
            return assetsPromise;
        }
        var base = String(assetsBase || '').replace(/\/+$/, '');
        assetsPromise = new Promise(function (resolve, reject) {
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = base + '/leaflet.draw.css';
            document.head.appendChild(css);

            var script = document.createElement('script');
            script.src = base + '/leaflet.draw.js';
            script.onload = function () { localize(); resolve(); };
            script.onerror = function () { assetsPromise = null; reject(new Error('leaflet.draw indisponível')); };
            document.body.appendChild(script);
        });
        return assetsPromise;
    }

    // Só as mensagens que aparecem no fluxo de polígono; o resto do
    // L.drawLocal (toolbar nativa, edição) não é usado.
    function localize() {
        var L = global.L;
        if (!L || !L.drawLocal) {
            return;
        }
        var handlers = L.drawLocal.draw.handlers;
        handlers.polygon.tooltip = {
            start: 'Toque ou clique no mapa para começar a desenhar a área.',
            cont: 'Toque ou clique para marcar o próximo ponto.',
            end: 'Toque em Concluir (ou no primeiro ponto) para fechar a área.'
        };
        handlers.polyline.error = DEFAULT_ERROR.message;
    }

    function toLatLngs(coords) {
        return (coords || []).map(function (pair) { return [Number(pair[1]), Number(pair[0])]; });
    }

    function toCoords(layer) {
        var ring = layer.getLatLngs();
        // Polígono simples: getLatLngs() devolve [[LatLng, ...]].
        while (Array.isArray(ring[0]) && ring.length) {
            ring = ring[0];
        }
        return ring.map(function (point) { return [point.lng, point.lat]; });
    }

    function button(container, id, label, icon, extraClass) {
        var btn = global.L.DomUtil.create('button', 'map-floating-btn' + (extraClass ? ' ' + extraClass : ''), container);
        btn.type = 'button';
        btn.id = id;
        btn.innerHTML = '<i class="fa-solid ' + icon + '" aria-hidden="true"></i> <span>' + label + '</span>';
        return btn;
    }

    function attach(map, options) {
        var L = global.L;
        if (!L || !map) {
            throw new Error('HabitawebMapDraw.attach: mapa Leaflet obrigatório');
        }
        options = options || {};
        var labels = Object.assign({}, DEFAULT_LABELS, options.labels || {});
        var shapeOptions = Object.assign({}, DEFAULT_SHAPE, options.shapeOptions || {});
        var drawError = Object.assign({}, DEFAULT_ERROR, options.drawError || {});
        var assetsBase = options.assetsBase || 'assets/js/leaflet-draw';

        var drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        var drawer = null;
        var state = 'idle';
        var vertexCount = 0;
        var buttons = {};

        var layoutCss = options.layout === 'row'
            ? 'flex-direction:row;flex-wrap:wrap;justify-content:center;gap:10px;'
            : 'flex-direction:column;gap:8px;align-items:stretch;';

        var Control = L.Control.extend({
            options: { position: options.position || 'topright' },
            onAdd: function () {
                var container = L.DomUtil.create('div', 'map-draw-toolbar leaflet-bar');
                container.style.cssText = 'border:none;background:none;box-shadow:none;display:flex;' + layoutCss;

                buttons.start  = button(container, 'btnStartDraw',  labels.start,  'fa-draw-polygon');
                buttons.finish = button(container, 'btnFinishDraw', labels.finish, 'fa-check');
                buttons.undo   = button(container, 'btnUndoDraw',   labels.undo,   'fa-rotate-left');
                buttons.cancel = button(container, 'btnCancelDraw', labels.cancel, 'fa-xmark');
                buttons.clear  = button(container, 'btnClearDraw',  labels.clear,  'fa-trash-can', 'text-danger');

                L.DomEvent.on(buttons.start, 'click', startDrawing);
                L.DomEvent.on(buttons.finish, 'click', finishDrawing);
                L.DomEvent.on(buttons.undo, 'click', undoVertex);
                L.DomEvent.on(buttons.cancel, 'click', cancelDrawing);
                L.DomEvent.on(buttons.clear, 'click', clear);

                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                render();
                return container;
            }
        });

        var control = new Control().addTo(map);

        function render() {
            if (!buttons.start) {
                return;
            }
            var drawing = state === 'drawing';
            buttons.start.hidden  = state !== 'idle';
            buttons.finish.hidden = !drawing;
            buttons.undo.hidden   = !drawing;
            buttons.cancel.hidden = !drawing;
            buttons.clear.hidden  = state !== 'drawn';
            buttons.finish.disabled = vertexCount < MIN_VERTICES;
            buttons.undo.disabled   = vertexCount < 1;
        }

        function setState(next, count) {
            state = next;
            vertexCount = typeof count === 'number' ? count : 0;
            render();
        }

        function startDrawing() {
            if (state !== 'idle') {
                return;
            }
            var original = buttons.start.innerHTML;
            buttons.start.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> <span>' + labels.loading + '</span>';
            buttons.start.disabled = true;

            loadAssets(assetsBase)
                .then(function () {
                    buttons.start.innerHTML = original;
                    buttons.start.disabled = false;
                    drawer = drawer || new L.Draw.Polygon(map, {
                        allowIntersection: false,
                        showArea: false,
                        drawError: drawError,
                        shapeOptions: shapeOptions
                    });
                    drawer.enable();
                })
                .catch(function (err) {
                    buttons.start.innerHTML = original;
                    buttons.start.disabled = false;
                    if (typeof options.onError === 'function') {
                        options.onError(err);
                    } else {
                        global.alert(labels.loadError);
                    }
                });
        }

        function finishDrawing() {
            if (!drawer || state !== 'drawing' || vertexCount < MIN_VERTICES) {
                return;
            }
            // completeShape() não confere cruzamento de linhas (só _finishShape,
            // o do clique no primeiro vértice, confere). Mesma regra aqui.
            if (drawer._poly && typeof drawer._poly.intersects === 'function' && drawer._poly.intersects()) {
                if (typeof drawer._showErrorTooltip === 'function') {
                    drawer._showErrorTooltip();
                }
                return;
            }
            drawer.completeShape();
        }

        function undoVertex() {
            if (drawer && state === 'drawing' && vertexCount > 0) {
                drawer.deleteLastVertex();
            }
        }

        function cancelDrawing() {
            if (drawer && state === 'drawing') {
                drawer.disable(); // dispara draw:drawstop -> volta ao ocioso
            }
        }

        // `silent` evita o callback quando quem chama já vai refazer a busca
        // (ex.: "Limpar filtros" da tela de busca). O clique no botão passa o
        // evento do DOM como argumento, que não tem `silent`.
        function clear(opts) {
            drawnItems.clearLayers();
            setState('idle');
            var silent = opts && opts.silent === true;
            if (!silent && typeof options.onCleared === 'function') {
                options.onCleared();
            }
        }

        function setPolygon(coords) {
            var latlngs = toLatLngs(coords);
            if (latlngs.length < MIN_VERTICES) {
                return null;
            }
            drawnItems.clearLayers();
            var layer = L.polygon(latlngs, shapeOptions);
            drawnItems.addLayer(layer);
            setState('drawn');
            return layer;
        }

        map.on('draw:drawstart', function () { setState('drawing', 0); });
        map.on('draw:drawvertex', function (event) {
            var count = event.layers && typeof event.layers.getLayers === 'function' ? event.layers.getLayers().length : vertexCount;
            setState('drawing', count);
        });
        map.on('draw:drawstop', function () {
            if (state === 'drawing') {
                setState('idle');
            }
        });
        map.on('draw:created', function (event) {
            drawnItems.clearLayers();
            drawnItems.addLayer(event.layer);
            setState('drawn');
            if (typeof options.onCreated === 'function') {
                options.onCreated(event.layer, toCoords(event.layer));
            }
        });

        return {
            control: control,
            layerGroup: drawnItems,
            startDrawing: startDrawing,
            cancelDrawing: cancelDrawing,
            clear: clear,
            setPolygon: setPolygon,
            hasPolygon: function () { return drawnItems.getLayers().length > 0; },
            getLayer: function () { return drawnItems.getLayers()[0] || null; },
            getCoords: function () { var layer = drawnItems.getLayers()[0]; return layer ? toCoords(layer) : null; }
        };
    }

    global.HabitawebMapDraw = { attach: attach, loadAssets: loadAssets };
})(window);
