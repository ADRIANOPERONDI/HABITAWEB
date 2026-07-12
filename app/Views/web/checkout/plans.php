<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<div class="container py-5">
    <div class="text-center mb-5">
        <h1 class="fw-bold">Escolha seu Plano</h1>
        <p class="lead text-muted">Desbloqueie todo o potencial do sistema com um de nossos planos.</p>
        
        <?php // Ícones em Font Awesome (carregado no layout) — as classes "bi bi-*"
              // usadas antes eram do Bootstrap Icons, que NENHUM layout carrega:
              // todos os ícones desta página (de conversão!) renderizavam em branco. ?>
        <?php if(session()->has('error')): ?>
            <div class="alert alert-warning mt-3">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= session('error') ?>
            </div>
        <?php endif; ?>

        <?php if(session()->has('message')): ?>
            <div class="alert alert-info mt-3">
                <i class="fa-solid fa-circle-info"></i> <?= session('message') ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row justify-content-center">
        <?php
            // Destaque "Mais popular" no plano do meio quando há 3+ opções.
            $popularIndex = count($plans) >= 3 ? (int) floor(count($plans) / 2) : -1;
        ?>
        <?php foreach($plans as $i => $plan): ?>
        <?php $isPopular = $i === $popularIndex; ?>
        <div class="col-md-4 mb-4">
            <div class="card h-100 shadow-sm <?= $isPopular ? 'border border-primary border-2 position-relative' : 'border-0' ?>">
                <?php if($isPopular): ?>
                    <span class="badge bg-primary position-absolute top-0 start-50 translate-middle rounded-pill px-3 py-2">
                        <i class="fa-solid fa-star me-1"></i> Mais popular
                    </span>
                <?php endif; ?>
                <div class="card-body text-center p-4">
                    <h3 class="card-title fw-bold mb-3"><?= esc($plan->nome) ?></h3>
                    <h2 class="display-5 fw-bold mb-3">R$ <?= number_format($plan->preco_mensal ?? $plan->preco, 2, ',', '.') ?> <small class="text-muted fs-6">/mês</small></h2>
                    <ul class="list-unstyled mb-4 text-start mx-auto" style="max-width: 200px;">
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> <?= esc($plan->limite_imoveis) ?> Imóveis</li>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> <?= esc($plan->limite_fotos) ?> Fotos/Imóvel</li>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> <?= esc($plan->limite_destaques) ?> Destaques</li>
                    </ul>
                    <a href="<?= site_url('checkout/plan/' . $plan->id) ?>" class="btn btn-primary btn-lg w-100 rounded-pill">Selecionar Plano</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?= $this->endSection() ?>
