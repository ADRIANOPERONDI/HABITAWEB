<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Gateways de Pagamento<?= $this->endSection() ?>
<?= $this->section('page_title') ?>
<div class="d-flex align-items-center justify-content-between w-100">
    <span>Configurações de Pagamento</span>
    <?php if (!empty($gateways)): ?>
    <a href="<?= site_url('admin/payment-gateways/sync') ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2">
        <i class="fa-solid fa-sync me-1"></i> Sincronizar Status
    </a>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="row g-4">
        <?php foreach ($gateways as $gateway): ?>
            <div class="col-xl-4 col-md-6">
                <div class="card h-100 shadow-sm border-0 rounded-4 overflow-hidden transition-all hover-translate-y <?= $gateway->is_primary ? 'border-primary' : '' ?>" style="<?= $gateway->is_primary ? 'border: 2px solid #6366f1 !important;' : '' ?>">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div class="p-2 rounded-3 bg-light me-3">
                                <?php if ($gateway->code === 'asaas'): ?>
                                    <i class="fa-solid fa-cloud-bolt text-primary fs-5"></i>
                                <?php elseif ($gateway->code === 'stripe'): ?>
                                    <i class="fa-brands fa-stripe text-primary fs-4"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-credit-card text-primary fs-5"></i>
                                <?php endif; ?>
                            </div>
                            <h5 class="m-0 fw-bold"><?= esc($gateway->name) ?></h5>
                        </div>
                        <?php if ($gateway->is_primary): ?>
                            <span class="badge bg-primary rounded-pill px-3 shadow-sm">PRINCIPAL</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body">
                        <p class="text-muted small mb-4"><?= esc($gateway->description) ?></p>
                        
                        <div class="mb-4">
                            <h6 class="text-uppercase text-muted xsmall fw-bold mb-3 ls-1">Métodos de Pagamento</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php 
                                $methods = $gateway->human_methods ?? []; 
                                ?>
                                <?php if (!empty($methods)): ?>
                                    <?php foreach ($methods as $method): ?>
                                        <span class="badge bg-light text-secondary border-0 px-3 py-2 rounded-3"><?= esc($method) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted small">Nenhum</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-3 rounded-4 bg-light bg-opacity-50 border border-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="form-check form-switch pt-1">
                                    <input class="form-check-input custom-switch toggle-gateway" type="checkbox" 
                                           id="active_<?= $gateway->id ?>" 
                                           data-id="<?= $gateway->id ?>"
                                           <?= $gateway->is_active ? 'checked' : '' ?>>
                                    <label class="form-check-label small fw-bold ms-2" for="active_<?= $gateway->id ?>">
                                        <?= $gateway->is_active ? 'ATIVO' : 'INATIVO' ?>
                                    </label>
                                </div>
                                
                                <div class="d-flex gap-2">
                                    <?php if (!$gateway->is_primary): ?>
                                        <form action="<?= site_url("admin/payment-gateways/set-primary/{$gateway->id}") ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-warning border rounded-pill px-3 py-2 shadow-sm group-hover-text-white" title="Definir como Principal" data-bs-toggle="tooltip">
                                                <i class="fas fa-star"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                         <button type="button" class="btn btn-sm btn-warning text-white border-0 rounded-pill px-3 py-2 shadow-sm pe-none" title="Este é o gateway principal">
                                            <i class="fas fa-star"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <a href="<?= site_url("admin/payment-gateways/configure/{$gateway->id}") ?>" 
                                       class="btn btn-sm btn-primary rounded-pill px-3 py-2 shadow-sm">
                                        <i class="fas fa-cog me-1 small"></i> Ajustes
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggles = document.querySelectorAll('.toggle-gateway');
    
    toggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const id = this.dataset.id;
            const label = this.nextElementSibling;
            
            label.textContent = this.checked ? 'ATIVO' : 'INATIVO';
            
            fetch(`<?= site_url('admin/payment-gateways/toggle/') ?>${id}`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    '<?= csrf_token() ?>': '<?= csrf_hash() ?>'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    Swal.fire('Erro!', data.message, 'error');
                    this.checked = !this.checked;
                    label.textContent = this.checked ? 'ATIVO' : 'INATIVO';
                }
            })
            .catch(error => {
                Swal.fire('Erro!', 'Falha na comunicação com o servidor.', 'error');
                this.checked = !this.checked;
                label.textContent = this.checked ? 'ATIVO' : 'INATIVO';
            });
        });
    });
});
</script>
<?= $this->endSection() ?>
