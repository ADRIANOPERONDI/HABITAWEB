<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .property-card { border: 1px solid #eee; border-radius: 8px; margin-bottom: 20px; overflow: hidden; text-decoration: none; color: inherit; display: block; }
        .property-img { width: 100%; height: 200px; object-fit: cover; }
        .property-body { padding: 15px; }
        .property-title { font-weight: bold; font-size: 18px; margin-bottom: 5px; }
        .property-price { color: #28a745; font-weight: bold; font-size: 20px; }
        .property-location { color: #666; font-size: 14px; margin-bottom: 10px; }
        .footer { text-align: center; font-size: 12px; color: #999; margin-top: 30px; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Novos Imóveis para Você!</h1>
            <p>Encontramos imóveis que combinam com seu alerta de busca.</p>
        </div>

        <?php foreach($properties as $property): ?>
        <a href="<?= site_url('imovel/' . $property->id) ?>" class="property-card">
            <?php 
                $cover = !empty($property->cover_image) ? $property->cover_image : 'assets/img/placeholder-house.png';
                $imgUrl = (strpos($cover, 'http') === 0) ? $cover : site_url($cover);
            ?>
            <img src="<?= $imgUrl ?>" class="property-img">
            <div class="property-body">
                <div class="property-title"><?= esc($property->titulo) ?></div>
                <div class="property-location"><?= esc($property->bairro) ?>, <?= esc($property->cidade) ?></div>
                <div class="property-price">R$ <?= number_format($property->preco, 2, ',', '.') ?></div>
            </div>
        </a>
        <?php endforeach; ?>

        <div class="footer">
            <p>Você está recebendo este e-mail porque se inscreveu para alertas de busca no Habitaweb.</p>
            <p>Frequência: <?= esc($alert['frequencia']) ?></p>
            <p><a href="<?= site_url('alertas/gerenciar') ?>">Gerenciar meus alertas</a> | <a href="<?= site_url('alertas/cancelar/' . base64_encode($alert['email'])) ?>">Cancelar inscrições</a></p>
        </div>
    </div>
</body>
</html>
