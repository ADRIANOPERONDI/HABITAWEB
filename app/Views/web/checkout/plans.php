<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<div class="container py-5">
    <div class="text-center mb-4">
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

        <?php if($leadPrices !== []): ?>
            <?php
                $rotulosNegocio = ['VENDA' => 'Venda', 'ALUGUEL' => 'Aluguel', 'TEMPORADA' => 'Temporada'];
                $trechosLead    = [];
                foreach ($leadPrices as $tipo => $regra) {
                    $trechosLead[] = ($rotulosNegocio[$tipo] ?? $tipo) . ' R$ ' . number_format((float) $regra->value, 2, ',', '.');
                }
            ?>
            <div class="alert alert-light border mt-3 mx-auto small" style="max-width: 640px;">
                <i class="fa-solid fa-tag me-1"></i>
                <strong>Preço de lead — igual em todos os planos:</strong> <?= esc(implode(' · ', $trechosLead)) ?>
            </div>
        <?php endif; ?>

        <?php if($rampBands !== []): ?>
            <?php
                $trechosRampa = [];
                foreach ($rampBands as $banda) {
                    $meses = $banda['mes_ate'] === null
                        ? 'a partir do mês ' . $banda['mes_de']
                        : ($banda['mes_de'] === $banda['mes_ate']
                            ? 'no mês ' . $banda['mes_de']
                            : 'nos meses ' . $banda['mes_de'] . '–' . $banda['mes_ate']);
                    $valor = $banda['percentual'] === 0
                        ? 'grátis'
                        : ($banda['percentual'] === 100 ? 'valor cheio' : $banda['percentual'] . '% do valor');
                    $trechosRampa[] = $meses . ' ' . $valor;
                }
            ?>
            <div class="alert alert-success border-0 rounded-4 mt-2 mx-auto" style="max-width: 640px;">
                <i class="fa-solid fa-gift me-1"></i>
                <strong>Lançamento (ciclo mensal):</strong> <?= esc(implode(', ', $trechosRampa)) ?>.
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
                    <h3 class="card-title fw-bold mb-1"><?= esc($plan->nome) ?></h3>
                    <?php if(!empty($plan->descricao)): ?>
                        <p class="text-muted small mb-3"><?= esc($plan->descricao) ?></p>
                    <?php endif; ?>
                    <h2 class="display-5 fw-bold mb-1">R$ <?= number_format((float) $plan->preco_mensal, 2, ',', '.') ?> <small class="text-muted fs-6">/mês</small></h2>
                    <?php if((float) $plan->preco_anual > 0): ?>
                        <div class="small text-muted mb-3">ou R$ <?= number_format((float) $plan->preco_anual, 2, ',', '.') ?>/ano (10 mensalidades, use os 12 meses)</div>
                    <?php else: ?>
                        <div class="mb-3"></div>
                    <?php endif; ?>
                    <ul class="list-unstyled mb-4 text-start mx-auto" style="max-width: 260px;">
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> <?= $plan->limite_imoveis_ativos === null ? 'Imóveis ilimitados' : esc($plan->limite_imoveis_ativos) . ' Imóveis' ?></li>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> <?= $plan->limite_fotos_por_imovel === null ? 'Fotos ilimitadas' : esc($plan->limite_fotos_por_imovel) . ' Fotos/Imóvel' ?></li>
                        <?php $turbos = $plan->turbosIncluidos(); ?>
                        <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i>
                            <?= $turbos === null ? 'Turbinadas ilimitadas' : ($turbos === 0 ? 'Turbinadas avulsas (R$ 50 / 7 dias)' : $turbos . ' Turbinadas/mês') ?>
                        </li>
                        <?php if($plan->turboBonusAnual() > 0): ?>
                            <li class="mb-2"><i class="fa-solid fa-plus text-success me-2"></i> +<?= $plan->turboBonusAnual() ?> turbinadas/mês no plano anual</li>
                        <?php endif; ?>
                        <?php if($plan->creditoLeadsMensal() > 0): ?>
                            <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> Crédito mensal de R$ <?= number_format($plan->creditoLeadsMensal(), 2, ',', '.') ?> em leads</li>
                        <?php endif; ?>
                        <?php foreach(array_intersect($plan->activeFeatures(), \App\Entities\PlanFeature::visiveis()) as $feature): ?>
                            <li class="mb-2"><i class="fa-solid fa-circle-check text-success me-2"></i> <?= esc(\App\Entities\PlanFeature::label($feature)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= site_url('checkout/plan/' . $plan->id) ?>" class="btn btn-primary btn-lg w-100 rounded-pill">Selecionar Plano</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?= $this->endSection() ?>
