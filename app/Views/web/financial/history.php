<?= $this->extend('Layouts/panel') ?>

<?= $this->section('title') ?>Histórico Financeiro<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <h1 class="mt-4">Histórico Financeiro</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="<?= site_url('painel') ?>">Dashboard</a></li>
        <li class="breadcrumb-item active">Financeiro</li>
    </ol>

    <div class="row">
        <!-- Status Assinatura -->
        <div class="col-xl-4">
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="m-0 fw-bold text-primary"><i class="fas fa-crown me-2"></i>Plano Atual</h5>
                </div>
                <div class="card-body">
                    <?php if ($currentSubscription && $currentSubscription->status === 'ACTIVE'): ?>
                        <div class="text-center py-3">
                            <h3 class="text-success fw-bold mb-1">ATIVO</h3>
                            <p class="text-muted">Renova em <?= date('d/m/Y', strtotime($currentSubscription->next_billing_date ?? $currentSubscription->data_fim)) ?></p>
                            <div class="d-grid">
                                <a href="<?= site_url('checkout/plans') ?>" class="btn btn-outline-primary">Mudar Plano</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <h3 class="text-secondary fw-bold mb-1">INATIVO</h3>
                            <p class="text-muted">Você não possui uma assinatura ativa.</p>
                            <div class="d-grid">
                                <a href="<?= site_url('checkout/plans') ?>" class="btn btn-primary">Assinar Agora</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tabela Histórico -->
        <div class="col-xl-8">
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="m-0 fw-bold"><i class="fas fa-history me-2"></i>Histórico de Transações</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Data</th>
                                    <th>Descrição</th>
                                    <th>Valor</th>
                                    <th>Método</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fas fa-file-invoice-dollar fa-3x mb-3 opacity-25"></i>
                                            <p class="mb-0">Nenhuma transação encontrada.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $trx): ?>
                                        <tr>
                                            <td class="ps-4"><?= date('d/m/Y H:i', strtotime($trx->created_at)) ?></td>
                                            <td>
                                                <span class="d-block fw-bold text-dark">
                                                    <?= $trx->type === 'SUBSCRIPTION' ? 'Assinatura' : 'Pagamento Avulso' ?>
                                                </span>
                                                <span class="small text-muted">Ref: <?= $trx->external_id ?></span>
                                            </td>
                                            <td class="fw-bold">R$ <?= number_format($trx->amount, 2, ',', '.') ?></td>
                                            <td>
                                                <?php if ($trx->method === 'PIX'): ?>
                                                    <span class="badge bg-success-soft text-success"><i class="brands fa-pix me-1"></i> PIX</span>
                                                <?php elseif ($trx->method === 'BOLETO'): ?>
                                                    <span class="badge bg-warning-soft text-warning"><i class="fas fa-barcode me-1"></i> Boleto</span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary-soft text-primary"><i class="fas fa-credit-card me-1"></i> Cartão</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($trx->status === 'RECEIVED' || $trx->status === 'CONFIRMED' || $trx->status === 'PAID'): ?>
                                                    <span class="badge bg-success">Pago</span>
                                                <?php elseif ($trx->status === 'PENDING'): ?>
                                                    <span class="badge bg-warning text-dark">Pendente</span>
                                                <?php elseif ($trx->status === 'OVERDUE'): ?>
                                                    <span class="badge bg-danger">Vencido</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?= $trx->status ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <a href="#" class="btn btn-sm btn-light text-muted" title="Ver Detalhes">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
