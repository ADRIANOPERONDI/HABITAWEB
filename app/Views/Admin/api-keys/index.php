<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Chaves de API<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Gestão de Chaves de API<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .api-key-card { border: none; border-radius: 16px; transition: all 0.3s; background: #fff; border: 1px solid #f0f0f0; }
    .api-key-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
    .key-display { font-family: 'Courier New', monospace; background: #f8f9fa; padding: 12px; border-radius: 8px; font-size: 0.85rem; word-break: break-all; }
    .status-badge-active { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    .status-badge-inactive { background: #6b7280; }
    .status-badge-revoked { background: #ef4444; }
    .btn-copy { transition: all 0.2s; }
    .btn-copy:hover { transform: scale(1.05); }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row mb-4 align-items-center">
    <div class="col-md-8">
        <p class="text-muted mb-0">
            <?php if ($isSuperAdmin): ?>
                Gerencie chaves de API para todas as contas do sistema.
            <?php else: ?>
                Gerencie as chaves de API da sua conta para integração com sistemas externos.
            <?php endif; ?>
        </p>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <button class="btn btn-primary rounded-pill px-4 shadow" data-bs-toggle="modal" data-bs-target="#modalCreateKey">
            <i class="fa-solid fa-key me-2"></i> Nova Chave de API
        </button>
    </div>
</div>

<!-- Alertas -->
<div class="row mb-4">
    <div class="col-12">
        <div class="alert alert-info border-0 rounded-4 d-flex align-items-start">
            <div class="me-3 mt-1">
                <i class="fa-solid fa-circle-info fa-2x"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-2">Segurança de API Keys</h6>
                <ul class="mb-0 small">
                    <li>As chaves são exibidas <strong>apenas uma vez</strong> na criação. Copie e guarde em local seguro.</li>
                    <li>Nunca compartilhe suas chaves em repositórios públicos ou códigos client-side.</li>
                    <li>Se uma chave for comprometida, <strong>revogue-a imediatamente</strong>.</li>
                    <li>Cada chave tem limite de <?= $keys[0]->rate_limit_per_hour ?? 1000 ?> requisições por hora.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Listagem de Chaves -->
<div class="row g-4">
    <?php if (empty($keys)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 py-5">
                <div class="text-center">
                    <i class="fa-solid fa-key fa-4x text-muted opacity-25 mb-3"></i>
                    <h5 class="fw-bold text-muted">Nenhuma chave de API cadastrada</h5>
                    <p class="text-muted">Crie sua primeira chave para começar a integrar com sistemas externos.</p>
                    <button class="btn btn-primary rounded-pill px-5 mt-3" data-bs-toggle="modal" data-bs-target="#modalCreateKey">
                        Criar Primeira Chave
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($keys as $key): ?>
            <div class="col-12 col-lg-6">
                <div class="api-key-card card p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="flex-grow-1">
                            <h5 class="fw-bold mb-1"><?= esc($key->name) ?></h5>
                            <?php if ($isSuperAdmin): ?>
                                <small class="text-muted">
                                    <i class="fa-solid fa-building me-1"></i>
                                    <?= esc($key->account_name) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <?php
                            $statusMap = [
                                'active' => 'Ativo',
                                'revoked' => 'Revogado',
                                'inactive' => 'Inativo'
                            ];
                            $statusClass = [
                                'active' => 'success',
                                'revoked' => 'danger',
                                'inactive' => 'secondary'
                            ];
                            $currentStatus = strtolower($key->status);
                        ?>
                        <span class="badge bg-<?= $statusClass[$currentStatus] ?? 'secondary' ?> text-white px-3 py-2 rounded-pill">
                            <?= $statusMap[$currentStatus] ?? ucfirst($currentStatus) ?>
                        </span>
                    </div>

                    <!-- Chave (Prefixo + Sufixo) -->
                    <div class="key-display mb-3">
                        <i class="fa-solid fa-terminal me-2 text-muted"></i>
                        <?= $key->getVisibleKey() ?>
                    </div>

                    <!-- Informações -->
                    <div class="row g-2 mb-3 small">
                        <div class="col-6">
                            <div class="text-muted">
                                <i class="fa-solid fa-gauge-high me-1"></i> Rate Limit
                            </div>
                            <div class="fw-bold"><?= number_format($key->rate_limit_per_hour) ?> req/h</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">
                                <i class="fa-solid fa-calendar me-1"></i> Criada em
                            </div>
                            <div class="fw-bold"><?= date('d/m/Y', strtotime($key->created_at)) ?></div>
                        </div>
                        <?php if ($key->last_used_at): ?>
                            <div class="col-6">
                                <div class="text-muted">
                                    <i class="fa-solid fa-clock me-1"></i> Último uso
                                </div>
                                <div class="fw-bold"><?= date('d/m/Y H:i', strtotime($key->last_used_at)) ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted">
                                    <i class="fa-solid fa-network-wired me-1"></i> IP
                                </div>
                                <div class="fw-bold"><?= $key->last_used_ip ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Ações -->
                    <div class="d-flex gap-2">
                        <?php if ($key->status !== 'revoked'): ?>
                            <button class="btn btn-sm <?= $key->status === 'active' ? 'btn-warning' : 'btn-success' ?> flex-grow-1"
                                    onclick="toggleKey(<?= $key->id ?>, '<?= $key->status ?>')">
                                <i class="fa-solid fa-power-off me-1"></i>
                                <?= $key->status === 'active' ? 'Desativar' : 'Ativar' ?>
                            </button>
                        <?php endif; ?>
                        
                        <button class="btn btn-sm btn-outline-danger" 
                                onclick="<?= $key->status === 'revoked' ? 'deleteKey(' . $key->id . ')' : 'revokeKey(' . $key->id . ')' ?>">
                            <i class="fa-solid fa-<?= $key->status === 'revoked' ? 'trash' : 'ban' ?> me-1"></i>
                            <?= $key->status === 'revoked' ? 'Deletar' : 'Revogar' ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Paginação -->
<?php if ($pager && $pager->getPageCount() > 1): ?>
    <div class="d-flex justify-content-center mt-5">
        <?= $pager->links('default', 'bootstrap_full') ?>
    </div>
<?php endif; ?>

<!-- Modal: Criar Chave -->
<div class="modal fade" id="modalCreateKey" tabindex="-1" data-bs-backdrop="false">
    <div class="modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-key text-primary me-2"></i>
                    Nova Chave de API
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCreateKey">
                    <?= csrf_field() ?>
                    
                    <?php if ($isSuperAdmin): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Conta <span class="text-danger">*</span></label>
                            <?php if (empty($accounts)): ?>
                                <div class="alert alert-warning py-2 small">
                                    <i class="fa-solid fa-exclamation-triangle me-1"></i>
                                    Nenhuma conta encontrada. A chave será criada para a conta principal.
                                </div>
                                <input type="hidden" name="account_id" value="<?= $currentAccountId ?>">
                            <?php else: ?>
                                <select name="account_id" class="form-select" required>
                                    <option value="">Selecione a conta...</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <?php 
                                            $accId = is_object($account) ? $account->id : ($account['id'] ?? '');
                                            $accNome = is_object($account) ? $account->nome : ($account['nome'] ?? 'Sem nome');
                                        ?>
                                        <option value="<?= $accId ?>"><?= esc($accNome) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nome da Chave <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Ex: Integração Site Principal" required>
                        <small class="text-muted">Nome para identificar onde esta chave será usada.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Rate Limit (requisições/hora)</label>
                        <input type="number" name="rate_limit_per_hour" class="form-control" value="1000" min="100" max="10000">
                        <small class="text-muted">Limite de requisições permitidas por hora.</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" onclick="createKey()">
                    <i class="fa-solid fa-plus me-2"></i> Gerar Chave
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Exibir Chave Criada (ÚNICA VEZ) -->
<div class="modal fade" id="modalShowKey" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-0 bg-success text-white rounded-top-4">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-check-circle me-2"></i>
                    API Key Criada com Sucesso!
                </h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 rounded-3">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    <strong>ATENÇÃO:</strong> Esta chave será exibida apenas uma vez. Copie agora!
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Sua API Key:</label>
                    <div class="input-group">
                        <input type="text" id="generatedKey" class="form-control font-monospace" readonly>
                        <button class="btn btn-outline-primary" onclick="copyKey()">
                            <i class="fa-solid fa-copy"></i> Copiar
                        </button>
                    </div>
                </div>

                <p class="text-muted small mb-0">
                    Use esta chave no header <code>Authorization: Bearer {sua-chave}</code>
                </p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-primary rounded-pill px-4" onclick="closeKeyModal()">
                    Entendi, Copiei a Chave
                </button>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Criar chave
function createKey() {
    const form = document.getElementById('formCreateKey');
    const formData = new FormData(form);

    Swal.fire({
        title: 'Gerando Chave...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: '<?= site_url('admin/api-keys') ?>',
        method: 'POST',
        data: Object.fromEntries(formData),
        dataType: 'json'
    }).done(function(response) {
        Swal.close();
        if (response.success) {
            // Fecha modal de criação
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalCreateKey'));
            modal.hide();
            form.reset();

            // Exibe chave gerada
            document.getElementById('generatedKey').value = response.plain_key;
            const showModal = new bootstrap.Modal(document.getElementById('modalShowKey'));
            showModal.show();
        } else {
            Swal.fire('Erro!', response.message, 'error');
        }
    }).fail(function() {
        Swal.close();
        Swal.fire('Erro!', 'Falha na comunicação com o servidor.', 'error');
    });
}

// Copiar chave
function copyKey() {
    const input = document.getElementById('generatedKey');
    input.select();
    document.execCommand('copy');
    
    Toast.fire({
        icon: 'success',
        title: 'Chave copiada!'
    });
}

// Fechar modal de exibição de chave
function closeKeyModal() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalShowKey'));
    modal.hide();
    location.reload();
}

// Toggle ativo/inativo
function toggleKey(id, currentStatus) {
    const action = currentStatus === 'active' ? 'desativar' : 'ativar';
    
    Swal.fire({
        title: `${action.charAt(0).toUpperCase() + action.slice(1)} chave?`,
        text: currentStatus === 'active' ? 'A chave não poderá ser usada até ser reativada.' : 'A chave poderá ser usada novamente.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sim, ' + action,
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `<?= site_url('admin/api-keys') ?>/${id}/toggle`,
                method: 'POST',
                data: {<?= csrf_token() ?>: '<?= csrf_hash() ?>'},
                dataType: 'json'
            }).done(function(response) {
                if (response.success) {
                    Swal.fire('Sucesso!', response.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Erro!', response.message, 'error');
                }
            });
        }
    });
}

// Revogar chave
function revokeKey(id) {
    Swal.fire({
        title: 'Revogar Chave?',
        text: 'Esta ação é IRREVERSÍVEL. A chave será permanentemente desabilitada.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sim, Revogar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `<?= site_url('admin/api-keys') ?>/${id}/revoke`,
                method: 'POST',
                data: {<?= csrf_token() ?>: '<?= csrf_hash() ?>'},
                dataType: 'json'
            }).done(function(response) {
                if (response.success) {
                    Swal.fire('Revogada!', response.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Erro!', response.message, 'error');
                }
            });
        }
    });
}

// Deletar chave
function deleteKey(id) {
    confirmAction(`<?= site_url('admin/api-keys') ?>/${id}`, 'DELETE', 'Deletar Chave Permanentemente?', 'Esta chave revogada será removida do sistema.');
}
</script>
<?= $this->endSection() ?>
