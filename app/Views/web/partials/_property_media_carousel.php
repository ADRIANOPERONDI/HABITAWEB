<?php
    $property = $property ?? null;
    if (!$property) {
        return;
    }

    // media_variant_url resolve a variante 'card' (~480px) quando existe e cai
    // no original quando não — cards nunca devem baixar a foto em tamanho cheio.
    $fallbackImage = !empty($property->cover_image)
        ? media_variant_url($property->cover_image, 'card')
        : base_url('assets/img/placeholder-house.png');

    $images = [];
    foreach (($property->carousel_images ?? []) as $img) {
        if (!empty($img)) {
            $images[] = media_variant_url($img, 'card');
        }
    }

    if (empty($images)) {
        $images[] = $fallbackImage;
    }

    // Um único contador de fotos por cartão, sempre com o TOTAL do anúncio.
    // A prévia carrega no máximo 5 fotos (MapSearchController::loadCardImages);
    // antes o selo sobre a foto contava só essas 5 e a linha de specs mostrava
    // o total (16), e o mesmo cartão exibia dois números diferentes.
    $preview = count($images);
    $total   = max($preview, (int) ($property->media_count ?? 0));
    $faltam  = $total - $preview;

    $carouselId = 'property-media-carousel-' . (int) $property->id . '-' . substr(md5($fallbackImage), 0, 8);
?>

<div id="<?= esc($carouselId) ?>" class="carousel slide premium-card-carousel h-100" data-bs-ride="false" data-bs-touch="true">
    <div class="carousel-inner h-100">
        <?php foreach($images as $index => $image): ?>
            <div class="carousel-item h-100 <?= $index === 0 ? 'active' : '' ?>">
                <?php
                    // A foto 0 é a visível. A 1 vem junto, em prioridade baixa:
                    // ela é o destino do primeiro toque em "próxima", e imagem
                    // lazy dentro de .carousel-item (display:none) NUNCA é
                    // pré-carregada pelo navegador — o slide aparecia em branco
                    // até o download terminar. Da 2 em diante segue lazy; o
                    // listener de slide.bs.carousel no layout promove o resto
                    // na primeira interação com o carrossel.
                    $loading  = $index <= 1 ? 'eager' : 'lazy';
                    $priority = $index === 0 ? 'high' : 'low';
                ?>
                <img src="<?= esc($image) ?>" loading="<?= $loading ?>" fetchpriority="<?= $priority ?>" alt="<?= esc($property->titulo) ?>" onerror="this.src='<?= base_url('assets/img/placeholder-house.png') ?>'">
            </div>
        <?php endforeach; ?>
        <?php if ($faltam > 0): ?>
            <?php // Último slide da prévia: deixa claro que há mais fotos e que elas ficam no anúncio (o clique segue o link do cartão). ?>
            <div class="carousel-item h-100 premium-carousel-more">
                <div class="premium-carousel-more-inner">
                    <strong>+<?= $faltam ?> <?= $faltam === 1 ? 'foto' : 'fotos' ?></strong>
                    <span>Abra o anúncio para ver todas as <?= $total ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if($preview > 1 || $faltam > 0): ?>
        <button class="carousel-control-prev premium-carousel-control" type="button" data-bs-target="#<?= esc($carouselId) ?>" data-bs-slide="prev" onclick="event.preventDefault(); event.stopPropagation();" aria-label="Foto anterior">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        </button>
        <button class="carousel-control-next premium-carousel-control" type="button" data-bs-target="#<?= esc($carouselId) ?>" data-bs-slide="next" onclick="event.preventDefault(); event.stopPropagation();" aria-label="Próxima foto">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
        </button>
        <div class="premium-carousel-count" aria-label="<?= $total ?> fotos no anúncio">
            <i class="fa-solid fa-camera" aria-hidden="true"></i> <span class="js-carousel-pos">1</span>&nbsp;/&nbsp;<?= $total ?>
        </div>
    <?php endif; ?>
</div>
