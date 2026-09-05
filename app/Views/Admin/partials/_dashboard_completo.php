<?php
$origemLabel = [
    'DIRETO'        => 'Acesso direto',
    'BUSCA'         => 'Buscadores',
    'REDES_SOCIAIS' => 'Redes sociais',
    'OUTRO'         => 'Outros sites',
];
?>

<!-- Evolução no período (vs. os 7 dias anteriores) -->
<?php if ($leadsComparado !== null || $viewsComparado !== null): ?>
<div class="row g-4 mb-4 animate-fade-in" style="animation-delay: 0.12s">
    <?php foreach (['Leads recebidos' => $leadsComparado, 'Visualizações' => $viewsComparado] as $label => $comp): ?>
        <?php if ($comp === null) continue; ?>
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4 d-flex align-items-center justify-content-between">
                    <div>
                        <p class="text-muted small fw-bold text-uppercase mb-1"><?= esc($label) ?> (7 dias)</p>
                        <h3 class="fw-bold mb-0"><?= (int) $comp['atual'] ?></h3>
                    </div>
                    <?php if ($comp['variacao_pct'] !== null): ?>
                        <span class="badge <?= $comp['variacao_pct'] >= 0 ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger' ?> rounded-pill px-3 py-2">
                            <i class="fa-solid fa-arrow-<?= $comp['variacao_pct'] >= 0 ? 'up' : 'down' ?> me-1"></i>
                            <?= number_format(abs($comp['variacao_pct']), 1, ',', '.') ?>%
                        </span>
                    <?php endif; ?>
                </div>
                <div class="px-4 pb-3 small text-muted">vs. <?= (int) $comp['anterior'] ?> no período anterior</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ANALYTICS ROW -->
<div class="row mb-5 g-4 animate-fade-in" style="animation-delay: 0.15s">
    <!-- Chart: Leads Performance -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-area text-primary me-2"></i> Performance de Leads (7 dias)</h5>
            </div>
            <div class="card-body p-4">
                <canvas id="leadsChart" height="100"></canvas>
            </div>

            <?php if (! empty($viewOrigins)): ?>
            <div class="card-footer bg-white border-0 p-4 pt-0">
                <p class="text-muted small fw-bold text-uppercase mb-2">De onde vêm seus acessos</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($viewOrigins as $origem => $views): ?>
                        <span class="badge bg-light text-dark px-3 py-2">
                            <?= esc($origemLabel[$origem] ?? $origem) ?>: <strong><?= (int) $views ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Market Comparison & Opportunities -->
    <div class="col-lg-4">
        <div class="d-flex flex-column gap-4 h-100">

            <!-- Market Price -->
            <div class="card border-0 shadow-sm flex-grow-1">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-muted text-uppercase small mb-3">Comparativo de Mercado</h6>

                    <?php if ($comparativoMercado): ?>
                        <div class="d-flex align-items-end gap-3 mb-2">
                             <h3 class="fw-bold mb-0">R$ <?= $stats['avg_ticket'] ?></h3>
                             <small class="<?= $stats['ticket_status'] === 'above' ? 'text-success' : 'text-danger' ?>">
                                 <i class="fa-solid fa-arrow-<?= $stats['ticket_status'] === 'above' ? 'up' : 'down' ?>"></i> vs Média
                             </small>
                        </div>
                        <p class="text-muted small">Seu valor médio vs R$ <?= $stats['market_avg_ticket'] ?> (Mercado)</p>
                        <div class="progress mb-3" style="height: 6px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= (int) $stats['ticket_pct'] ?>%"></div>
                        </div>

                        <?php if ($marketShare !== null): ?>
                            <hr class="my-3">
                            <p class="text-muted small mb-1">Participação na oferta da sua praça</p>
                            <p class="fw-bold mb-2"><?= number_format($marketShare['oferta_share_pct'], 1, ',', '.') ?>%
                                <span class="text-muted fw-normal small">(<?= $marketShare['imoveis_conta'] ?> de <?= $marketShare['imoveis_cidade'] ?> imóveis)</span>
                            </p>
                            <p class="text-muted small mb-1">Participação nos leads da sua praça</p>
                            <p class="fw-bold mb-0"><?= number_format($marketShare['leads_share_pct'], 1, ',', '.') ?>%
                                <span class="text-muted fw-normal small">(<?= $marketShare['leads_conta'] ?> de <?= $marketShare['leads_cidade'] ?> leads)</span>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-3">
                            O comparativo completo (participação na oferta e nos leads da sua praça) é
                            exclusivo do plano Diamante.
                        </p>
                        <a href="<?= site_url('admin/subscription/plans') ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                            Conhecer o Diamante
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Opportunities Alert -->
            <div class="card border-0 shadow-sm flex-grow-1 bg-warning-soft">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark text-uppercase small mb-0"><i class="fa-solid fa-lightbulb text-warning me-2"></i> Oportunidades</h6>
                        <span class="badge bg-warning text-dark"><?= count($opportunities) ?></span>
                    </div>

                    <?php if(empty($opportunities)): ?>
                        <p class="small text-muted mb-0">Nenhuma oportunidade crítica detectada. Seus imóveis estão performando bem!</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                        <?php foreach($opportunities as $opp): ?>
                             <div class="d-flex align-items-center bg-white p-2 rounded shadow-sm">
                                 <div class="flex-grow-1 ms-2">
                                     <div class="details small fw-bold text-dark text-truncate" style="max-width: 150px;"><?= esc($opp->titulo) ?></div>
                                     <div class="text-muted extra-small"><?= $opp->visitas_count ?> views • 0 leads</div>
                                 </div>
                                 <a href="<?= site_url('admin/properties/' . $opp->id . '/edit') ?>" class="btn btn-sm btn-light text-primary"><i class="fa-solid fa-pen"></i></a>
                             </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('leadsChart').getContext('2d');
    const rootStyles = getComputedStyle(document.documentElement);
    const primaryColor = (rootStyles.getPropertyValue('--primary-color') || '#2563eb').trim();
    const secondaryColor = (rootStyles.getPropertyValue('--secondary-color') || '#7c3aed').trim();
    const tertiaryColor = (rootStyles.getPropertyValue('--tertiary-color') || '#14b8a6').trim();

    const hexToRgba = (hex, alpha) => {
        const clean = (hex || '').replace('#', '').trim();
        if (clean.length !== 6) return `rgba(37, 99, 235, ${alpha})`;
        const r = parseInt(clean.slice(0, 2), 16);
        const g = parseInt(clean.slice(2, 4), 16);
        const b = parseInt(clean.slice(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    };

    const gradient = ctx.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, hexToRgba(primaryColor, 0.35));
    gradient.addColorStop(0.6, hexToRgba(secondaryColor, 0.20));
    gradient.addColorStop(1, hexToRgba(tertiaryColor, 0.05));

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartData['labels']) ?>,
            datasets: [{
                label: 'Leads',
                data: <?= json_encode($chartData['values']) ?>,
                borderColor: primaryColor,
                backgroundColor: gradient,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#fff',
                pointBorderColor: primaryColor,
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, color: '#6b7280' },
                    grid: { color: hexToRgba(primaryColor, 0.12) }
                },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php if ($stats['is_global']): ?>
<div class="row g-4 mb-5 animate-fade-in" style="animation-delay: 0.2s">
    <div class="col-12">
        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-globe me-2 text-primary"></i> Visão Global do Portal</h5>
    </div>
    <!-- Total Imóveis -->
    <div class="col-12 col-md-4">
        <div class="card metric-card h-100 border-0 shadow-sm bg-primary text-white">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50 small fw-bold text-uppercase">Total de Anúncios</span>
                    <i class="fa-solid fa-house-circle-exclamation opacity-50"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= $stats['total_imoveis_global'] ?></h2>
            </div>
        </div>
    </div>
    <!-- Total Contas -->
    <div class="col-12 col-md-4">
        <div class="card metric-card h-100 border-0 shadow-sm" style="background: var(--secondary-gradient); color: #fff;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50 small fw-bold text-uppercase">Imobiliárias & Corretores</span>
                    <i class="fa-solid fa-users opacity-50"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= $stats['total_contas_global'] ?></h2>
            </div>
        </div>
    </div>
    <!-- Total Leads -->
    <div class="col-12 col-md-4">
        <div class="card metric-card h-100 border-0 shadow-sm bg-tertiary text-white">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50 small fw-bold text-uppercase">Leads Gerados (Total)</span>
                    <i class="fa-solid fa-paper-plane opacity-50"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= $stats['total_leads_global'] ?></h2>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
