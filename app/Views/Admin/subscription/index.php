<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Minha Assinatura<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Minha Assinatura<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <!-- Status Atual -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h5 class="fw-bold mb-1">Plano Atual</h5>
                        <div class="text-muted small">Status da sua conta</div>
                    </div>
                    <?php if(isset($lastTransaction) && $lastTransaction): ?>
                        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill">Pagamento Pendente</span>
                    <?php elseif($subscription && in_array(strtoupper($subscription->status), ['ATIVA', 'ACTIVE'])): ?>
                        <span class="badge bg-success text-white px-3 py-2 rounded-pill">Ativo</span>
                    <?php else: ?>
                        <span class="badge bg-danger text-white px-3 py-2 rounded-pill">Inativo</span>
                    <?php endif; ?>
                </div>

                <?php if(isset($pendingSubscription) && $pendingSubscription): ?>
                <div class="alert alert-warning border-0 shadow-sm mb-4">
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-clock fa-2x me-3 opacity-50"></i>
                        <div class="w-100">
                            <h6 class="fw-bold mb-1">Fatura em Aberto (<?= lang('Payments.status_' . strtolower($pendingSubscription->status)) ?>)</h6>
                            <p class="small mb-3">
                                <?php if(isset($pendingSubscription->custom_pending_msg) && $pendingSubscription->custom_pending_msg): ?>
                                    <?= $pendingSubscription->custom_pending_msg ?>
                                <?php else: ?>
                                    Você tem uma assinatura do plano <strong><?= esc($pendingPlan ? $pendingPlan->nome : 'Novo Plano') ?></strong> aguardando confirmação.
                                <?php endif; ?>
                            </p>
                            
                            <?php 
                                $invoiceUrl = '#';
                                if(isset($lastTransaction)) {
                                    $invoiceUrl = $lastTransaction->invoice_url ?? '#';
                                    
                                    // Fallback para metadados se a coluna estiver vazia
                                    if ($invoiceUrl === '#' && !empty($lastTransaction->metadata)) {
                                        $meta = is_string($lastTransaction->metadata) ? json_decode($lastTransaction->metadata, true) : (array)$lastTransaction->metadata;
                                        $invoiceUrl = $meta['invoice_url'] ?? '#';
                                    }
                                }
                            ?>

                            <div class="d-flex gap-2">
                                <a href="<?= $invoiceUrl ?>" target="_blank" class="btn btn-dark">
                                    <i class="fas fa-external-link-alt me-2"></i> Pagar Fatura
                                </a>
                                
                                <form action="<?= site_url('admin/subscription/cancel/' . $pendingSubscription->id) ?>" method="POST" id="form-cancel-subscription">
                                    <?= csrf_field() ?>
                                     <button type="button" class="btn btn-outline-danger btn-cancel-subscription"><?= lang('Payments.confirm_cancel_btn') ?></button>
                                </form>
                            </div>
                            
                            <?php if(isset($lastTransaction->payment_method) && $lastTransaction->payment_method == 'PIX'): ?>
                                <small class="d-block mt-2 text-muted"><i class="fas fa-qrcode"></i> Pagamento via Pix disponível no link acima</small>
                            <?php elseif(isset($lastTransaction->payment_method) && $lastTransaction->payment_method == 'BOLETO'): ?>
                                <small class="d-block mt-2 text-muted"><i class="fas fa-barcode"></i> Boleto disponível no link acima</small>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="p-3 bg-light rounded-3 d-flex align-items-center gap-4 mb-4">
                    <div class="bg-white p-3 rounded-circle shadow-sm text-primary">
                        <i class="fa-solid fa-crown fa-2x"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0"><?= esc($plan ? $plan->nome : 'Gratuito / Sem Plano') ?></h3>
                        <div class="text-muted">
                            <?php if($plan): ?>
                                R$ <?= number_format($plan->preco_mensal, 2, ',', '.') ?> / mês
                            <?php else: ?>
                                Grátis
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold mb-3">Uso do Plano</h6>
                <div class="mb-2 d-flex justify-content-between text-muted small">
                    <span>Imóveis Ativos</span>
                    <span>
                        <strong><?= $usage['active_properties'] ?></strong> 
                        / <?= $usage['is_unlimited'] ? '∞' : $usage['limit'] ?>
                    </span>
                </div>
                <?php 
                    $percent = 0;
                    if (!$usage['is_unlimited'] && $usage['limit'] > 0) {
                        $percent = ($usage['active_properties'] / $usage['limit']) * 100;
                    }
                ?>
                <div class="progress mb-4" style="height: 8px;">
                    <div class="progress-bar <?= $percent > 90 ? 'bg-danger' : 'bg-primary' ?>" role="progressbar" style="width: <?= $percent ?>%"></div>
                </div>

                <hr>

                <div class="d-flex justify-content-between text-muted small">
                    <div>Assinado em: <?= $subscription ? date('d/m/Y', strtotime($subscription->data_inicio)) : '-' ?></div>
                    <div>Renova em: <?= $subscription && $subscription->data_fim ? date('d/m/Y', strtotime($subscription->data_fim)) : 'Indeterminado' ?></div>
                </div>
            </div>
        </div>

        <h4 class="fw-bold mb-3">Mudar de Plano</h4>
        <div class="row g-3">
            <?php foreach($allPlans as $p): ?>
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm <?= ($plan && $plan->id == $p->id) ? 'border border-primary border-2' : '' ?>">
                    <div class="card-body p-4 text-center d-flex flex-column">
                        <?php if($plan && $plan->id == $p->id): ?>
                            <div class="position-absolute top-0 start-50 translate-middle badge bg-primary">Atual</div>
                        <?php endif; ?>
                        
                        <h5 class="fw-bold mb-3"><?= esc($p->nome) ?></h5>
                        <div class="mb-4">
                            <span class="h3 fw-bold">R$ <?= number_format($p->preco_mensal, 2, ',', '.') ?></span><span class="text-muted">/mês</span>
                        </div>
                        
                        <ul class="list-unstyled text-start mb-4 small text-muted flex-grow-1">
                            <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 
                                <?= $p->limite_imoveis_ativos === null ? 'Imóveis Ilimitados' : $p->limite_imoveis_ativos . ' Imóveis Ativos' ?>
                            </li>
                            <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 
                                <?= $p->limite_fotos_por_imovel ?> Fotos por Imóvel
                            </li>
                            <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 
                                <?= $p->destaques_mensais ?> Destaques/mês
                            </li>
                        </ul>

                        <?php if($plan && $plan->id == $p->id): ?>
                            <button class="btn btn-outline-primary w-100 rounded-pill disabled shadow-none">Plano Atual</button>
                        <?php else: ?>
                            <button type="button" 
                                    class="btn btn-primary w-100 rounded-pill btn-change-plan shadow-sm" 
                                    data-id="<?= $p->id ?>" 
                                    data-name="<?= esc($p->nome) ?>"
                                    <?= ($plan && $plan->preco_mensal > $p->preco_mensal) ? 'data-is-downgrade="true"' : '' ?>>
                                <i class="fas fa-arrow-right me-1"></i> Mudar de Plano
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Sidebar Ajuda (Opcional) -->
    <div class="col-12 col-lg-4">
        <div class="card bg-primary text-white border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-2">Precisa de mais?</h5>
                <p class="small text-white-50 mb-4">Entre em contato com nossa equipe comercial para planos empresariais personalizados.</p>
                <button class="btn btn-light text-primary w-100 fw-bold">Falar com Consultor</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        console.log('Subscription scripts loaded');

        $(document).on('click', '.btn-change-plan', function() {
            const planId = $(this).data('id');
            const planName = $(this).data('name');
            const isDowngrade = $(this).data('is-downgrade');
            const upgradeUrl = '<?= site_url('admin/subscription/upgrade/') ?>' + planId;
            const previewUrl = '<?= site_url('admin/subscription/preview-upgrade/') ?>' + planId;

            console.log('Change plan clicked:', {planId, planName, isDowngrade});

            // Mostrar loading inicial
            Swal.fire({
                title: 'Processando...',
                text: 'Calculando detalhes da troca de plano',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Buscar preview do upgrade
            $.get(previewUrl, function(data) {
                console.log('Preview data received:', data);

                if (data.is_downgrade) {
                    Swal.fire({
                        title: 'Downgrade Indisponível',
                        html: `Você está tentando mudar para o plano <strong>${data.new_plan_name}</strong>, que possui um valor inferior ao seu plano atual.<br><br>Para realizar um downgrade, você deve primeiro <strong>cancelar sua assinatura atual</strong> e, após o término do período ou cancelamento, contratar o novo plano desejado.`,
                        icon: 'warning',
                        confirmButtonText: 'Entendi',
                        confirmButtonColor: '#3085d6'
                    });
                    return;
                }
                
                let config = {
                    title: 'Confirmar Troca de Plano',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, Alterar Plano',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                };

                if (data.is_upgrade && data.pro_rata > 0) {
                    config.html = `
                        <div class="text-start">
                            <p>Você está fazendo um upgrade para o <strong>${data.new_plan_name}</strong>.</p>
                            <div class="alert alert-info border-0 py-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Cobrança Proporcional (Pró-rata):</strong><br>
                                Será gerada uma cobrança única de <strong>R$ ${data.formatted_pro_rata}</strong> relativa aos dias restantes até o próximo vencimento.
                            </div>
                            <p class="small text-muted">As próximas mensalidades serão no novo valor integral de R$ ${data.new_price.toLocaleString('pt-BR', {minimumFractionDigits: 2})}.</p>
                        </div>
                    `;
                } else {
                    config.text = `Deseja mudar para o plano ${data.new_plan_name}?`;
                }

                Swal.fire(config).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = upgradeUrl;
                    }
                });
            }).fail(function(xhr) {
                console.error('Preview error:', xhr);
                Swal.fire('Erro!', 'Não foi possível calcular a troca de plano.', 'error');
            });
        });

        $('.btn-cancel-subscription').click(function(e) {
            e.preventDefault();
            Swal.fire({
                title: '<?= lang('Payments.confirm_cancel_title') ?>',
                text: '<?= lang('Payments.confirm_cancel_text') ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#344767',
                confirmButtonText: '<?= lang('Payments.confirm_cancel_btn') ?>',
                cancelButtonText: '<?= lang('Payments.cancel_btn') ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#form-cancel-subscription').submit();
                }
            });
        });
    });
</script>
<?= $this->endSection() ?>
