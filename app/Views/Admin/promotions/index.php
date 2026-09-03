<?= $this->extend('Layouts/master') ?>

<?= $this->section('page_title') ?>Turbinar Anúncio<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="animate-fade-in">
    <div class="row mb-5">
        <div class="col-12">
            <div class="card card-premium bg-dark text-white p-4 border-0">
                <div class="card-body d-flex align-items-center gap-4">
                    <div class="metric-icon bg-warning text-dark" style="width: 80px; height: 80px; font-size: 2.5rem;">
                        <i class="fa-solid fa-rocket"></i>
                    </div>
                    <div>
                        <h2 class="fw-bold mb-1">Destaque seu imóvel agora!</h2>
                        <p class="text-white-50 mb-0">Imóveis turbinados recebem até 10x mais contatos. Escolha o melhor plano para o seu objetivo.</p>
                        <div class="mt-2 small">
                            <i class="fa-solid fa-house-circle-check text-warning me-1"></i> <?= esc($property->titulo) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?= view('Admin/partials/alerts') ?>

    <?php
        // Turbinadas incluídas no plano (Fase 1) — o mesmo pacote das compras
        // avulsas abaixo, só que sem custo, dentro da cota mensal.
        $jaTurbinado = false;
        foreach ($activePromos as $promo) {
            if ($promo->tipo_promocao === \App\Services\PromotionService::TIPO_TURBO && $promo->ativo) {
                $jaTurbinado = true;
                break;
            }
        }
        $temCota = ($quota['incluidas'] ?? 0) !== 0; // null = ilimitado, >0 = tem cota
    ?>
    <?php if ($temCota): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div>
                        <h6 class="fw-bold mb-1"><i class="fa-solid fa-gauge-high text-success me-1"></i> Turbinadas do plano</h6>
                        <div class="text-muted small">
                            <?php if ($quota['incluidas'] === null): ?>
                                <?= (int) $quota['usadas'] ?> usada(s) este mês · cota ilimitada
                            <?php else: ?>
                                <?= (int) $quota['usadas'] ?> usada(s) de <?= (int) $quota['incluidas'] ?>
                                — <?= (int) $quota['restantes'] ?> restante(s) este mês
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($jaTurbinado): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2">Este imóvel já está turbinado</span>
                    <?php elseif ($quota['restantes'] === null || $quota['restantes'] > 0): ?>
                        <form action="<?= site_url('admin/properties/' . $property->id . '/turbo/cota') ?>" method="post" class="quota-form">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-success rounded-pill fw-bold px-4">
                                <i class="fa-solid fa-bolt me-1"></i> Usar turbinada do plano
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="badge bg-secondary rounded-pill px-3 py-2">Cota do mês esgotada</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

     <div class="row mb-4">
         <div class="col-md-12">
             <div class="card bg-light">
                 <div class="card-body">
                     <h5><i class="fas fa-rocket text-primary"></i> Destaque seu anúncio!</h5>
                     <div class="row g-4">
                         <?php foreach ($packages as $pkg): ?>
            <?php 
                $isActive = false;
                $expiry = null;
                foreach ($activePromos as $promo) {
                    if ($promo->tipo_promocao === $pkg->tipo_promocao && $promo->ativo) {
                        $isActive = true;
                        $expiry = $promo->data_fim;
                        break;
                    }
                }
                
                // Escolha de ícone e gradiente baseado no tipo
                $gradient = 'var(--primary-gradient)';
                $icon = 'fa-bolt';
                if($pkg->tipo_promocao == 'SUPER_DESTAQUE') { $gradient = 'var(--secondary-gradient)'; $icon = 'fa-star'; }
                if($pkg->tipo_promocao == 'VITRINE') { $gradient = 'linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%)'; $icon = 'fa-gem'; }
            ?>
            <div class="col-md-4">
                <div class="card card-premium h-100 border-0 <?= $isActive ? 'border-success' : '' ?>">
                    <div class="card-header border-0 bg-transparent p-4 pb-0 text-center">
                        <div class="metric-icon mx-auto mb-3" style="background: <?= $gradient ?>; color: #fff;">
                            <i class="fa-solid <?= $icon ?>"></i>
                        </div>
                        <h4 class="fw-bold mb-0"><?= esc($pkg->nome) ?></h4>
                    </div>
                    <div class="card-body p-4 text-center">
                        <div class="mb-3">
                            <span class="fs-1 fw-bold" style="color: #1e293b !important;">R$ <?= number_format($pkg->preco, 2, ',', '.') ?></span>
                            <small class="text-muted d-block">por <?= $pkg->duracao_dias ?> dias</small>
                        </div>
                        
                        <div class="d-flex flex-column gap-2 text-start small mt-4 mb-4">
                            <?php if($pkg->tipo_promocao == 'SUPER_DESTAQUE'): ?>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Exibição Prioritária (Topo)</div>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Selo Dourado Reluzente</div>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Relatórios de Cliques</div>
                            <?php elseif($pkg->tipo_promocao == 'DESTAQUE'): ?>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Acima dos anúncios comuns</div>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Selo Prata de Destaque</div>
                            <?php else: ?>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Apareça na Página Inicial</div>
                                <div><i class="fa-solid fa-check text-success me-2"></i> Destaque em Categorias</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer border-0 bg-transparent p-4 pt-0">
                        <?php if ($isActive): ?>
                            <button class="btn btn-light w-100 rounded-pill fw-bold text-success" disabled>
                                <i class="fa-solid fa-check-circle me-1"></i> Ativo até <?= date('d/m', strtotime($expiry)) ?>
                            </button>
                        <?php else: ?>
                            <form action="<?= site_url('admin/promotions/store/' . $property->id) ?>" method="post" class="promo-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="package_key" value="<?= esc($pkg->chave) ?>">
                                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold" style="background: <?= $gradient ?> !important;">
                                    Turbinar Agora <i class="fa-solid fa-arrow-right ms-2"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$('.promo-form').on('submit', function(e) {
    e.preventDefault();
    const form = this;
    Swal.fire({
        title: 'Confirmar Contratação?',
        text: "Deseja impulsionar este imóvel agora?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: 'var(--primary-color)',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Sim, Turbinar!',
        cancelButtonText: 'Cancelar',
        borderRadius: '24px'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
});

$('.quota-form').on('submit', function(e) {
    e.preventDefault();
    const form = this;
    Swal.fire({
        title: 'Usar turbinada do plano?',
        text: 'Consome uma das turbinadas incluídas no seu plano este mês.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: 'var(--primary-color)',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Sim, usar!',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
});
</script>
<?= $this->endSection() ?>
