<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Detalhes da Transação #<?= $transaction['id'] ?><?= $this->endSection() ?>
<?= $this->section('page_title') ?>Detalhes Financeiros<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .metric-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
    .bg-primary-soft { background-color: rgba(var(--primary-rgb), 0.1) !important; color: var(--primary-color) !important; }
    .bg-secondary-soft { background-color: rgba(var(--secondary-rgb), 0.1) !important; color: var(--secondary-color) !important; }
    .bg-success-soft { background-color: rgba(var(--tertiary-rgb), 0.1) !important; color: var(--tertiary-color) !important; }
    .bg-info-soft { background-color: rgba(13, 202, 240, 0.1); color: #0dcaf0; }
    .bg-warning-soft { background-color: rgba(255, 193, 7, 0.1); color: #ffc107; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4 animate-fade-in">
    <div>
        <a href="<?= site_url('admin/payments/transactions') ?>" class="btn btn-light btn-sm rounded-pill px-3 mb-2 shadow-sm border">
            <i class="fa-solid fa-arrow-left me-2 text-primary"></i> Voltar para Lista
        </a>
        <h4 class="fw-bold mb-0">Comprovante Digital #<?= $transaction['id'] ?></h4>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-light text-muted border py-2 px-3 rounded-pill">
            <i class="fa-solid fa-calendar-alt me-2 text-primary"></i> <?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?>
        </span>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Informações Principais -->
    <div class="col-md-8 animate-fade-in" style="animation-delay: 0.1s">
        <div class="card card-premium border-0 shadow-lg mb-4">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-file-invoice-dollar me-2 text-primary"></i> Resumo do Pagamento</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-4 align-items-center">
                    <div class="col-md-6">
                        <div class="d-flex align-items-center">
                            <div class="metric-icon bg-success-soft me-3">
                                <i class="fa-solid fa-money-bill-wave"></i>
                            </div>
                            <div>
                                <label class="small text-muted fw-bold text-uppercase mb-0">Valor Recebido</label>
                                <h2 class="fw-extrabold text-success mb-0">R$ <?= number_format($transaction['amount'], 2, ',', '.') ?></h2>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 text-end">
                        <label class="small text-muted fw-bold text-uppercase mb-1">Status do Processamento</label>
                        <div>
                            <?php
                            $statusColors = [
                                'CONFIRMED' => 'success',
                                'PENDING' => 'warning',
                                'FAILED' => 'danger',
                                'CANCELLED' => 'secondary'
                            ];
                            $statusLabels = [
                                'CONFIRMED' => 'Confirmado',
                                'PENDING' => 'Pendente',
                                'FAILED' => 'Falhou',
                                'CANCELLED' => 'Cancelado'
                            ];
                            $statusColor = $statusColors[$transaction['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $statusColor ?> fs-6 rounded-pill px-4 py-2 shadow-sm text-uppercase"><?= $statusLabels[$transaction['status']] ?? $transaction['status'] ?></span>
                        </div>
                    </div>

                    <div class="col-12">
                        <hr class="opacity-50">
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex align-items-center">
                            <?php
                            $methods = [
                                'PIX' => ['icon' => 'fa-bolt', 'color' => 'primary', 'label' => 'PIX (Instantâneo)'],
                                'BOLETO' => ['icon' => 'fa-barcode', 'color' => 'warning', 'label' => 'Boleto Bancário'],
                                'CREDIT_CARD' => ['icon' => 'fa-credit-card', 'color' => 'success', 'label' => 'Cartão de Crédito']
                            ];
                            $m = $methods[$transaction['payment_method']] ?? ['icon' => 'fa-money-bill', 'color' => 'secondary', 'label' => $transaction['payment_method']];
                            ?>
                            <div class="metric-icon bg-<?= $m['color'] ?>-soft me-3">
                                <i class="fa-solid <?= $m['icon'] ?>"></i>
                            </div>
                            <div>
                                <label class="small text-muted fw-bold text-uppercase mb-0">Método Utilizado</label>
                                <div class="fw-bold text-dark"><?= $m['label'] ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-4 border-dashed border">
                            <label class="small text-muted fw-bold text-uppercase mb-1 d-block"><i class="fa-solid fa-key me-1"></i> ID de Referência (Gateway)</label>
                            <code class="text-primary fw-bold small"><?= esc($transaction['gateway_transaction_id']) ?></code>
                        </div>
                    </div>
                </div>

                <div class="row mt-4 pt-4 border-top">
                    <div class="col-md-6 border-end">
                        <p class="small text-muted text-uppercase fw-bold mb-1"><i class="fa-solid fa-calendar-plus me-1 text-primary"></i> Data de Registro</p>
                        <p class="fw-bold text-dark mb-0"><?= date('d/m/Y H:i:s', strtotime($transaction['created_at'])) ?></p>
                    </div>
                    <div class="col-md-6 ps-4">
                        <p class="small text-muted text-uppercase fw-bold mb-1"><i class="fa-solid fa-clock-rotate-left me-1 text-secondary"></i> Última Movimentação</p>
                        <p class="fw-bold text-dark mb-0"><?= date('d/m/Y H:i:s', strtotime($transaction['updated_at'])) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metadados -->
        <?php if (!empty($transaction['metadata'])): ?>
        <div class="card card-premium border-0 shadow-lg animate-fade-in" style="animation-delay: 0.2s">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-terminal me-2 text-secondary"></i> Logs de Resposta do Gateway</h5>
            </div>
            <div class="card-body p-4">
                <div class="bg-dark rounded-4 p-4 shadow-inner" style="max-height: 350px; overflow-y: auto;">
                    <pre class="mb-0 text-info small"><code><?= json_encode(json_decode($transaction['metadata']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></code></pre>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Barra Lateral -->
    <div class="col-md-4 animate-fade-in" style="animation-delay: 0.3s">
        <!-- Conta Responsável -->
        <?php if ($isAdmin && $account): ?>
        <div class="card card-premium border-0 shadow-lg mb-4">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-building me-2 text-primary"></i> Entidade Pagadora</h5>
            </div>
            <div class="card-body p-4 text-center">
                <div class="mb-3 d-flex justify-content-center">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($account->nome ?? $account->name) ?>&background=rgba(var(--primary-rgb),0.2)&color=<?= str_replace('#', '', app_setting('style.primary_color', '#6366f1')) ?>&size=100&bold=true" class="rounded-4 shadow-sm" width="80">
                </div>
                
                <h6 class="fw-bold text-dark mb-1"><?= esc($account->nome ?? $account->name) ?></h6>
                <p class="small text-muted mb-4"><?= esc($account->email) ?></p>
                
                <div class="p-3 bg-light rounded-4 mb-3 text-start">
                    <div class="mb-2">
                        <label class="small text-muted text-uppercase fw-bold mb-0">CPF/CNPJ</label>
                        <p class="fw-bold text-dark mb-0"><?= esc($account->cpf_cnpj ?: 'Não informado') ?></p>
                    </div>
                    <div>
                        <label class="small text-muted text-uppercase fw-bold mb-0">Tipo de Conta</label>
                        <p class="fw-bold text-dark mb-0"><?= esc($account->tipo ?? 'Bronze') ?></p>
                    </div>
                </div>
                
                <a href="<?= site_url('admin/accounts/view/' . $account->id) ?>" class="btn btn-primary btn-sm w-100 rounded-pill shadow-sm">
                    <i class="fa-solid fa-magnifying-glass me-1"></i> Perfil da Conta
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assinatura Vinculada -->
        <?php if ($subscription): ?>
        <div class="card card-premium border-0 shadow-lg h-100">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-gem me-2 text-info"></i> Plano Vinculado</h5>
            </div>
            <div class="card-body p-4">
                <div class="text-center p-3 bg-info bg-opacity-10 rounded-4 mb-4">
                    <div class="metric-icon bg-white text-info mx-auto mb-2 shadow-sm">
                         <i class="fa-solid fa-crown text-warning"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0"><?= $plan ? esc($plan->nome) : 'Assinatura Ativa' ?></h5>
                    <span class="badge bg-info rounded-pill px-3 py-1"><?= $subscription->status ?></span>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                            <span class="small text-muted fw-bold text-uppercase">ID da Assinatura</span>
                            <span class="fw-bold text-dark">#<?= $subscription->id ?></span>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                            <span class="small text-muted fw-bold text-uppercase">Próxima Renovação</span>
                            <span class="fw-bold text-primary"><?= $subscription->proximo_pagamento ? date('d/m/Y', strtotime($subscription->proximo_pagamento)) : '--/--/----' ?></span>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <a href="<?= site_url('admin/subscription') ?>" class="btn btn-outline-info btn-sm w-100 rounded-pill">
                        <i class="fa-solid fa-repeat me-1"></i> Detalhes da Assinatura
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
