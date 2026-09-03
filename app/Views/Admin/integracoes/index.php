<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Integrações<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Integrações<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .integration-card { border: 1px solid #f0f0f0; border-radius: 16px; transition: all .3s; background: #fff; height: 100%; }
    .integration-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,.08); }
    .integration-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; background: #eef2ff; color: #4f46e5; }
    .stat-chip { background: #f8f9fa; border-radius: 8px; padding: 8px 12px; font-size: .85rem; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted mb-0">
            Conecte o sistema que a sua imobiliária já usa: os imóveis entram automaticamente no Habitaweb, e os
            leads capturados no portal voltam para o CRM de origem. Imóveis importados são espelhos — só o status
            (pausar/publicar) e as fotos podem ser editados por aqui; os demais dados vêm da própria origem a cada
            sincronização.
        </p>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($overview as $row): ?>
        <?php
            $provider    = $row['provider'];
            $integration = $row['integration'];
            $status      = $integration->status ?? 'NOT_CONFIGURED';

            // status é saúde da CONEXÃO; pausa é só is_active, independente
            // (ver IntegrationService::toggleActive) — "Pausado" nunca é um
            // status isolado, só a combinação dos dois.
            [$badgeClass, $badgeLabel] = match (true) {
                $integration === null                                            => ['secondary', 'Não configurado'],
                $status === \App\Models\AccountIntegrationModel::STATUS_CONNECTED && ! $integration->is_active
                                                                                  => ['warning', 'Conectado (pausado)'],
                $status === \App\Models\AccountIntegrationModel::STATUS_CONNECTED => ['success', 'Conectado'],
                $status === \App\Models\AccountIntegrationModel::STATUS_ERROR     => ['danger', 'Erro'],
                default                                                          => ['secondary', 'Aguardando teste'],
            };
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="integration-card p-4 d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="integration-icon"><i class="fa-solid fa-plug"></i></div>
                    <div class="flex-grow-1">
                        <h5 class="mb-1"><?= esc($provider->name) ?></h5>
                        <span class="badge bg-<?= $badgeClass ?>"><?= esc($badgeLabel) ?></span>
                    </div>
                </div>

                <?php if ($integration !== null && $status === \App\Models\AccountIntegrationModel::STATUS_ERROR && $integration->last_test_message): ?>
                    <div class="alert alert-danger py-2 px-3 small mb-3">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        <?= esc($integration->last_test_message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($integration !== null): ?>
                    <div class="d-flex gap-2 mb-3 flex-wrap">
                        <span class="stat-chip">
                            <i class="fa-solid fa-house me-1 text-muted"></i>
                            <?= (int) $row['synced'] ?> imóvel(is)
                        </span>
                        <?php if ($row['unconfirmed'] > 0): ?>
                            <span class="stat-chip text-warning">
                                <i class="fa-solid fa-circle-exclamation me-1"></i>
                                <?= (int) $row['unconfirmed'] ?> de/para a revisar
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($integration->last_sync_at): ?>
                        <p class="text-muted small mb-3">
                            Última sincronização:
                            <?= esc(\CodeIgniter\I18n\Time::parse((string) $integration->last_sync_at)->humanize()) ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted small mb-3 flex-grow-1">
                        Importe o catálogo e devolva os leads capturados no portal para o CRM.
                    </p>
                <?php endif; ?>

                <div class="mt-auto d-flex gap-2">
                    <a href="<?= site_url('admin/integracoes/' . $provider->code) ?>" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="fa-solid fa-gear me-1"></i>
                        <?= $integration === null ? 'Configurar' : 'Gerenciar' ?>
                    </a>
                    <?php if ($provider->docs_url): ?>
                        <a href="<?= esc($provider->docs_url, 'attr') ?>" target="_blank" rel="noopener noreferrer"
                           class="btn btn-outline-secondary btn-sm" title="Documentação da API">
                            <i class="fa-solid fa-book"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($overview === []): ?>
        <div class="col-12">
            <div class="alert alert-info">Nenhum conector disponível no momento.</div>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
