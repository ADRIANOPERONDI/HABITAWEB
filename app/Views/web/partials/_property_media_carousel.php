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

    $carouselId = 'property-media-carousel-' . (int) $property->id . '-' . substr(md5($fallbackImage), 0, 8);
?>

<div id="<?= esc($carouselId) ?>" class="carousel slide premium-card-carousel h-100" data-bs-ride="false" data-bs-touch="true">
    <div class="carousel-inner h-100">
        <?php foreach($images as $index => $image): ?>
            <div class="carousel-item h-100 <?= $index === 0 ? 'active' : '' ?>">
                <img src="<?= esc($image) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" alt="<?= esc($property->titulo) ?>" onerror="this.src='<?= base_url('assets/img/placeholder-house.png') ?>'">
            </div>
        <?php endforeach; ?>
    </div>

    <?php if(count($images) > 1): ?>
        <button class="carousel-control-prev premium-carousel-control" type="button" data-bs-target="#<?= esc($carouselId) ?>" data-bs-slide="prev" onclick="event.preventDefault(); event.stopPropagation();" aria-label="Foto anterior">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        </button>
        <button class="carousel-control-next premium-carousel-control" type="button" data-bs-target="#<?= esc($carouselId) ?>" data-bs-slide="next" onclick="event.preventDefault(); event.stopPropagation();" aria-label="Próxima foto">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
        </button>
        <div class="premium-carousel-count">
            <i class="fa-solid fa-camera"></i> <?= count($images) ?>
        </div>
    <?php endif; ?>
</div>
