<?php
    $property = $property ?? null;
    if (!$property) {
        return;
    }

    $fallbackImage = !empty($property->cover_image)
        ? (strpos($property->cover_image, 'http') === 0 ? $property->cover_image : base_url($property->cover_image))
        : base_url('assets/img/placeholder-house.png');

    $images = [];
    foreach (($property->carousel_images ?? []) as $img) {
        if (!empty($img)) {
            $images[] = strpos($img, 'http') === 0 ? $img : base_url($img);
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
