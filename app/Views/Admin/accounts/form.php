<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Editar Conta: <?= esc($account->nome) ?><?= $this->endSection() ?>
<?= $this->section('page_title') ?>Configurações da Conta<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row mb-4">
    <div class="col-md-8">
        <a href="<?= site_url('admin/accounts') ?>" class="btn btn-link text-decoration-none p-0 mb-3 text-muted">
            <i class="fa-solid fa-arrow-left me-1"></i> Voltar para a lista
        </a>
        <h4 class="fw-bold">Gestão da Conta: <?= esc($account->nome) ?></h4>
        <p class="text-muted small">Gerencie informações cadastrais e planos de assinatura.</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Tabs Navigation -->
        <ul class="nav nav-pills mb-4 bg-light p-1 rounded-pill" id="accountTabs" role="tablist" style="width: fit-content;">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill px-4 fw-bold" id="details-tab" data-bs-toggle="pill" data-bs-target="#details" type="button" role="tab">
                    <i class="fa-solid fa-user-gear me-2"></i>Dados Cadastrais
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill px-4 fw-bold" id="subscription-tab" data-bs-toggle="pill" data-bs-target="#subscription" type="button" role="tab">
                    <i class="fa-solid fa-credit-card me-2"></i>Plano e Assinatura
                </button>
            </li>
        </ul>

        <div class="tab-content" id="accountTabsContent">
            <!-- Details Tab -->
            <div class="tab-pane fade show active" id="details" role="tabpanel">
                <div class="card border-0 shadow-sm rounded-4 animate-fade-in">
                    <div class="card-body p-4">
                        <form action="<?= site_url('admin/accounts/' . $account->id . '/update') ?>" method="post">
                            <?= csrf_field() ?>
                            
                            <div class="row g-4">
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Nome / Razão Social</label>
                                    <input type="text" name="nome" class="form-control form-control-lg border-2 bg-light bg-opacity-50" value="<?= old('nome', $account->nome) ?>" required>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Tipo de Conta</label>
                                    <select name="tipo_conta" class="form-select form-control-lg border-2 bg-light bg-opacity-50">
                                        <option value="PF" <?= old('tipo_conta', $account->tipo_conta) == 'PF' ? 'selected' : '' ?>>Pessoa Física (Anunciante)</option>
                                        <option value="CORRETOR" <?= old('tipo_conta', $account->tipo_conta) == 'CORRETOR' ? 'selected' : '' ?>>Corretor Autônomo</option>
                                        <option value="IMOBILIARIA" <?= old('tipo_conta', $account->tipo_conta) == 'IMOBILIARIA' ? 'selected' : '' ?>>Imobiliária</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Documento (CPF/CNPJ)</label>
                                    <input type="text" name="documento" class="form-control form-control-lg border-2 bg-light bg-opacity-50" value="<?= old('documento', $account->documento) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">E-mail de Contato</label>
                                    <input type="email" name="email" class="form-control form-control-lg border-2 bg-light bg-opacity-50" value="<?= old('email', $account->email) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">WhatsApp</label>
                                    <input type="text" name="whatsapp" class="form-control form-control-lg border-2 bg-light bg-opacity-50" value="<?= old('whatsapp', $account->whatsapp) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">CRECI (Se houver)</label>
                                    <input type="text" name="creci" class="form-control form-control-lg border-2 bg-light bg-opacity-50" value="<?= old('creci', $account->creci) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Status da Conta</label>
                                    <select name="status" class="form-select form-control-lg border-2 bg-light bg-opacity-50">
                                        <option value="ACTIVE" <?= old('status', $account->status) == 'ACTIVE' ? 'selected' : '' ?>>Ativa</option>
                                        <option value="INACTIVE" <?= old('status', $account->status) == 'INACTIVE' ? 'selected' : '' ?>>Inativa / Suspensa</option>
                                    </select>
                                </div>
                            </div>

                            <hr class="my-4 opacity-50">

                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold shadow">
                                    Salvar Alterações
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Subscription Tab -->
            <div class="tab-pane fade" id="subscription" role="tabpanel">
                <div class="card border-0 shadow-sm rounded-4 animate-fade-in mb-4">
                    <div class="card-body p-4" id="subscription-container">
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Carregando dados da assinatura...</p>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 animate-fade-in d-none" id="upgrade-card">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3">Alterar Plano (Upgrade/Downgrade)</h6>
                        <form id="upgrade-form">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Selecione o Novo Plano</label>
                                    <select name="plan_id" class="form-select border-2 bg-light" id="plan-selector">
                                        <!-- Plans loaded via JS -->
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-dark w-100 rounded-pill fw-bold">Alterar Agora</button>
                                </div>
                            </div>
                            <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i> A alteração será enviada imediatamente para o gateway de pagamento.</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Informações de Sistema</h6>
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">ID do Banco:</span>
                        <span class="badge bg-light text-dark border">##<?= $account->id ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Criado em:</span>
                        <span class="text-dark small fw-bold"><?= $account->created_at->format('d/m/Y H:i') ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Última atualização:</span>
                        <span class="text-dark small fw-bold"><?= $account->updated_at->format('d/m/Y H:i') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info border-0 rounded-4 shadow-sm p-4">
            <div class="d-flex">
                <i class="fa-solid fa-circle-info mt-1 me-3 fs-5"></i>
                <div class="small">
                    <strong>Gestão Financeira:</strong> Como Super Admin, você pode forçar a troca de planos ou suspender contas inadimplentes. Todas as ações são sincronizadas com o Asaas.
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(document).ready(function() {
    const accountId = <?= $account->id ?>;
    
    // Load Subscription Data
    $('#subscription-tab').on('shown.bs.tab', function() {
        loadSubscription();
    });

    function loadSubscription() {
        $.get(`<?= site_url('admin/accounts') ?>/${accountId}/subscription`, function(data) {
            let html = '';
            
            if (!data.subscription) {
                html = `
                    <div class="text-center py-4">
                        <i class="fa-solid fa-circle-exclamation fa-3x text-warning mb-3 opacity-50"></i>
                        <h5 class="fw-bold">Nenhuma assinatura ativa</h5>
                        <p class="text-muted">Esta conta ainda não possui um plano de assinatura vinculado.</p>
                    </div>
                `;
            } else {
                const sub = data.subscription;
                const statusBadge = sub.status === 'ACTIVE' ? 'bg-success' : (sub.status === 'SUSPENDED' ? 'bg-warning' : 'bg-danger');
                
                html = `
                    <div class="d-flex justify-content-between align-items-start mb-4">
                        <div>
                            <span class="badge ${statusBadge} rounded-pill px-3 mb-2">${sub.status}</span>
                            <h4 class="fw-bold mb-0">Assinatura #${sub.id}</h4>
                            <p class="text-muted small">Gateway: <span class="badge bg-light text-dark border">${data.gateway}</span></p>
                        </div>
                        <div class="d-flex gap-2">
                            ${sub.status === 'ACTIVE' ? 
                                `<button onclick="suspendSub()" class="btn btn-outline-warning btn-sm rounded-pill px-3"><i class="fa-solid fa-pause me-1"></i> Suspender</button>` : 
                                ''
                            }
                            <button onclick="cancelSub()" class="btn btn-outline-danger btn-sm rounded-pill px-3"><i class="fa-solid fa-trash me-1"></i> Cancelar</button>
                        </div>
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <div class="col-sm-6">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-1">ID Externo (Asaas)</label>
                            <span class="text-dark fw-bold">${sub.asaas_subscription_id || 'N/A'}</span>
                        </div>
                        <div class="col-sm-6">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-1">Método de Pagamento</label>
                            <span class="text-dark fw-bold">${sub.payment_method || 'N/A'}</span>
                        </div>
                        <div class="col-sm-6">
                            <label class="text-muted small text-uppercase fw-bold d-block mb-1">Próximo Vencimento</label>
                            <span class="text-dark fw-bold">${sub.next_billing_date ? new Date(sub.next_billing_date).toLocaleDateString('pt-BR') : 'N/A'}</span>
                        </div>
                    </div>
                `;
                
                $('#upgrade-card').removeClass('d-none');
                
                // Load Plans in selector
                let plansHtml = '<option value="">Selecione um plano...</option>';
                data.plans.forEach(plan => {
                    plansHtml += `<option value="${plan.id}" ${sub.plan_id == plan.id ? 'selected' : ''}>${plan.nome} - R$ ${plan.preco_mensal}/mês</option>`;
                });
                $('#plan-selector').html(plansHtml);
            }
            
            $('#subscription-container').html(html);
        });
    }

    // Actions
    window.suspendSub = function() {
        Swal.fire({
            title: 'Suspender Assinatura?',
            text: "O cliente perderá o acesso e a cobrança será pausada no Asaas.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            confirmButtonText: 'Sim, suspender',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(`<?= site_url('admin/accounts') ?>/${accountId}/subscription/suspend`, function(res) {
                    Swal.fire('Sucesso!', res.success, 'success');
                    loadSubscription();
                }).fail(err => Swal.fire('Erro', err.responseJSON.error, 'error'));
            }
        });
    }

    window.cancelSub = function() {
        Swal.fire({
            title: 'Cancelar Assinatura?',
            text: "Esta ação é irreversível e encerrará a recorrência no Asaas.",
            icon: 'danger',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Sim, cancelar permanentemente',
            cancelButtonText: 'Manter ativa'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(`<?= site_url('admin/accounts') ?>/${accountId}/subscription/cancel`, function(res) {
                    Swal.fire('Cancelada!', res.success, 'success');
                    loadSubscription();
                }).fail(err => Swal.fire('Erro', err.responseJSON.error, 'error'));
            }
        });
    }

    $('#upgrade-form').on('submit', function(e) {
        e.preventDefault();
        const planId = $('#plan-selector').val();
        
        Swal.fire({
            title: 'Alterar Plano?',
            text: "O valor das faturas futuras será atualizado no Asaas.",
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Sim, alterar plano',
            cancelButtonText: 'Não, manter'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(`<?= site_url('admin/accounts') ?>/${accountId}/subscription/upgrade`, { plan_id: planId }, function(res) {
                    Swal.fire('Sucesso!', res.success, 'success');
                    loadSubscription();
                }).fail(err => Swal.fire('Erro', err.responseJSON.error, 'error'));
            }
        });
    });
});
</script>
<?= $this->endSection() ?>
