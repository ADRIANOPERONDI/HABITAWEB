<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<div class="container py-5">
    <div class="text-center mb-5">
        <h1 class="fw-bold">Escolha seu Plano</h1>
        <p class="lead text-muted">Desbloqueie todo o potencial do sistema com um de nossos planos.</p>
        
        <?php if(session()->has('error')): ?>
            <div class="alert alert-warning mt-3">
                <i class="bi bi-exclamation-triangle"></i> <?= session('error') ?>
            </div>
        <?php endif; ?>
        
        <?php if(session()->has('message')): ?>
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> <?= session('message') ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row justify-content-center">
        <?php foreach($plans as $plan): ?>
        <div class="col-md-4 mb-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body text-center p-4">
                    <h3 class="card-title fw-bold mb-3"><?= esc($plan->nome) ?></h3>
                    <h2 class="display-5 fw-bold mb-3">R$ <?= number_format($plan->preco_mensal ?? $plan->preco, 2, ',', '.') ?> <small class="text-muted fs-6">/mês</small></h2>
                    <ul class="list-unstyled mb-4 text-start mx-auto" style="max-width: 200px;">
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> <?= esc($plan->limite_imoveis) ?> Imóveis</li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> <?= esc($plan->limite_fotos) ?> Fotos/Imóvel</li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> <?= esc($plan->limite_destaques) ?> Destaques</li>
                    </ul>
                    <a href="<?= site_url('checkout/plan/' . $plan->id) ?>" class="btn btn-primary btn-lg w-100 rounded-pill">Selecionar Plano</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?= $this->endSection() ?>
