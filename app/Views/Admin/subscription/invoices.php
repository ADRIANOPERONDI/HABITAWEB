<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Minhas Faturas<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Minhas Faturas<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="fw-bold mb-1">Histórico de Pagamentos</h5>
                <p class="text-muted small mb-0">Confira suas faturas e transações.</p>
            </div>
            <a href="<?= site_url('admin/subscription') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-arrow-left me-2"></i> Voltar
            </a>
        </div>

        <?php if(empty($transactions)): ?>
            <div class="text-center py-5">
                <i class="fa-solid fa-file-invoice-dollar fa-3x text-muted opacity-25 mb-3"></i>
                <p class="text-muted">Nenhuma fatura encontrada.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 py-3 rounded-start">Data</th>
                            <th class="py-3">Plano/Descrição</th>
                            <th class="py-3">Método</th>
                            <th class="py-3">Valor</th>
                            <th class="py-3">Status</th>
                            <th class="pe-3 py-3 rounded-end text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transactions as $t): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-bold"><?= date('d/m/Y', strtotime($t->created_at)) ?></div>
                                    <div class="small text-muted"><?= date('H:i', strtotime($t->created_at)) ?></div>
                                </td>
                                <td>
                                    <?php if($t->plan_name): ?>
                                        <span class="badge bg-light text-dark border"><?= esc($t->plan_name) ?></span>
                                    <?php else: ?>
                                        Assinatura
                                    <?php endif; ?>
                                    <div class="small text-muted mt-1">Ref: <?= substr($t->gateway_transaction_id ?? 'N/A', 0, 8) ?>...</div>
                                </td>
                                <td>
                                    <?php 
                                        $icon = 'fa-credit-card';
                                        $label = 'Cartão';
                                        if($t->payment_method == 'PIX') { $icon = 'fa-qrcode'; $label = 'PIX'; }
                                        if($t->payment_method == 'BOLETO') { $icon = 'fa-barcode'; $label = 'Boleto'; }
                                    ?>
                                    <span class="badge bg-light text-secondary border">
                                        <i class="fa-solid <?= $icon ?> me-1"></i> <?= $label ?>
                                    </span>
                                </td>
                                <td class="fw-bold text-dark">
                                    R$ <?= number_format($t->amount, 2, ',', '.') ?>
                                </td>
                                <td>
                                    <?php
                                        $statusClass = 'bg-secondary';
                                        $statusLabel = $t->status;
                                        switch(strtoupper($t->status)) {
                                            case 'PAID':
                                            case 'RECEIVED':
                                            case 'CONFIRMED':
                                            case 'SUCCEEDED':
                                                $statusClass = 'bg-success';
                                                $statusLabel = 'Pago';
                                                break;
                                            case 'PENDING':
                                            case 'AWAITING_PAYMENT':
                                                $statusClass = 'bg-warning text-dark';
                                                $statusLabel = 'Pendente';
                                                break;
                                            case 'FAILED':
                                                $statusClass = 'bg-danger';
                                                $statusLabel = 'Falhou';
                                                break;
                                            case 'CANCELED':
                                                $statusClass = 'bg-dark';
                                                $statusLabel = 'Cancelado';
                                                break;
                                        }
                                    ?>
                                    <span class="badge <?= $statusClass ?> rounded-pill px-3"><?= $statusLabel ?></span>
                                </td>
                                <td class="pe-3 text-end">
                                    <?php 
                                        // Tentar link externo se houver metadados (simplificado)
                                        // Se for Pendente, dar opção de pagar (redirecionar para tela de sucesso/checkout se possível, ou link externo)
                                        // Como não temos URL fácil aqui sem json_decode do metadata, vamos apenas mostrar detalhes ou botão dummy se precisar.
                                    ?>
                                    <button class="btn btn-sm btn-light border" disabled title="Ver Detalhes">
                                        <i class="fa-solid fa-eye text-muted"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
