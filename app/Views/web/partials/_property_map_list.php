<?php
    $total = $total ?? count($properties ?? []);
    $hasMore = $has_more ?? false;
    $nextPage = $next_page ?? null;
?>

<?php if(empty($properties)): ?>
    <div class="empty-premium">
        <div>
            <i class="fa-solid fa-map-location-dot fa-3x mb-3 opacity-25"></i>
            <h5 class="fw-bold text-dark mb-2">Nenhum imóvel nesta área</h5>
            <p class="small mb-0">Mova o mapa, ajuste os filtros ou apague o desenho para ampliar a busca.</p>
        </div>
    </div>
<?php else: ?>
    <div class="map-list-grid">
        <?php foreach($properties as $property): ?>
            <?php
                $isSponsored = $property->is_destaque || (isset($property->highlight_level) && $property->highlight_level > 0);
            ?>
            <article class="premium-property-card map-property-card" id="property-card-<?= $property->id ?>" data-id="<?= $property->id ?>">
                <a href="<?= site_url('imovel/' . $property->id) ?>" target="_blank" class="premium-property-link">
                    <div class="premium-media">
                        <?= view('web/partials/_property_media_carousel', ['property' => $property]) ?>
                        <div class="premium-badges">
                            <span class="premium-badge"><?= $property->tipo_negocio === 'VENDA' ? 'Venda' : 'Aluguel' ?></span>
                            <?php if($isSponsored): ?>
                                <span class="premium-badge sponsored"><i class="fa-solid fa-certificate"></i> Patrocinado</span>
                            <?php endif; ?>
                            <?php if($property->is_novo && !$isSponsored): ?>
                                <span class="premium-badge">Novo</span>
                            <?php endif; ?>
                            <?php if($property->is_verified || ($property->account_verified ?? false)): ?>
                                <span class="premium-badge verified"><i class="fa-solid fa-circle-check"></i> Verificado</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="premium-card-body">
                        <div>
                            <h3 class="premium-location"><?= esc($property->bairro) ?>, <?= esc($property->cidade) ?></h3>
                            <p class="premium-card-title"><?= esc($property->titulo) ?></p>
                        </div>

                        <div class="premium-specs">
                            <?php if($property->area_total): ?>
                                <span><i class="fa-solid fa-ruler-combined"></i> <?= (int)$property->area_total ?>m²</span>
                            <?php endif; ?>
                            <?php if($property->quartos): ?>
                                <span><i class="fa-solid fa-bed"></i> <?= $property->quartos ?></span>
                            <?php endif; ?>
                            <?php if($property->banheiros): ?>
                                <span><i class="fa-solid fa-bath"></i> <?= $property->banheiros ?></span>
                            <?php endif; ?>
                            <?php if($property->vagas): ?>
                                <span><i class="fa-solid fa-car"></i> <?= $property->vagas ?></span>
                            <?php endif; ?>
                            <?php if(isset($property->media_count) && (int)$property->media_count > 1): ?>
                                <span><i class="fa-solid fa-camera"></i> <?= (int)$property->media_count ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="premium-price-row">
                            <div>
                                <div class="premium-price">R$ <?= number_format($property->preco, 2, ',', '.') ?></div>
                                <?php if($property->tipo_negocio === 'ALUGUEL'): ?>
                                    <div class="small text-muted">por mês</div>
                                <?php endif; ?>
                            </div>
                            <span class="premium-card-cta">Ver imóvel</span>
                        </div>
                    </div>
                </a>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if($hasMore && $nextPage): ?>
        <div class="d-flex justify-content-center mt-3">
            <button type="button" class="btn btn-outline-primary btn-premium" id="btnLoadMoreMap" data-next-page="<?= (int)$nextPage ?>">
                <i class="fa-solid fa-plus"></i> Carregar mais
            </button>
        </div>
    <?php endif; ?>
<?php endif; ?>
