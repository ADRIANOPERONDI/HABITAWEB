<?= $this->extend('Layouts/panel') ?>

<?= $this->section('title') ?>Detalhes da Transação<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="d-flex align-items-center justify-content-between mt-4 mb-4">
        <div>
            <h1>Detalhes da Transação</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= site_url('painel') ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= site_url('painel/financeiro') ?>">Financeiro</a></li>
                    <li class="breadcrumb-item active">Detalhes</li>
                </ol>
            </nav>
        </div>
        <a href="<?= site_url('painel/financeiro') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Voltar
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-5">
                        <div class="mb-3">
                            <i class="fas fa-check-circle fa-4x text-success"></i>
                        </div>
                        <h2 class="fw-bold">Pagamento Recebido</h2>
                        <p class="text-muted">ID da Transação: <?= esc($transaction->external_id) ?></p>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted small text-uppercase">Pagador</h6>
                            <p class="fw-bold fs-5 mb-0"><?= esc(service('auth')->user()->username ?? 'Cliente') ?></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <h6 class="text-muted small text-uppercase">Data</h6>
                            <p class="fw-bold fs-5 mb-0"><?= date('d/m/Y H:i', strtotime($transaction->created_at)) ?></p>
                        </div>
                    </div>

                    <hr>

                    <div class="row mb-4">
                        <div class="col-6">
                            <p class="mb-0 fw-bold">Descrição</p>
                        </div>
                        <div class="col-6 text-end">
                            <p class="mb-0 text-muted"><?= esc($transaction->type === 'SUBSCRIPTION' ? 'Renovação de Assinatura' : 'Serviço Avulso') ?></p>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-6">
                            <p class="mb-0 fw-bold">Método</p>
                        </div>
                        <div class="col-6 text-end">
                            <p class="mb-0 text-muted"><?= esc($transaction->method) ?></p>
                        </div>
                    </div>

                    <div class="row bg-light p-3 rounded mb-4">
                        <div class="col-6">
                            <h5 class="mb-0 fw-bold text-dark">Total Pago</h5>
                        </div>
                        <div class="col-6 text-end">
                            <h5 class="mb-0 fw-bold text-dark">R$ <?= number_format($transaction->amount, 2, ',', '.') ?></h5>
                        </div>
                    </div>

                    <div class="text-center">
                        <button class="btn btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i> Imprimir Comprovante
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
