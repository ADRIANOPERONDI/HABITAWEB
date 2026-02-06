<?= $this->extend('Layouts/master') ?>

<?= $this->section('page_title') ?>Pagamento do Destaque<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card card-premium p-4 text-center">
            <div class="mb-4">
                <i class="fa-solid fa-rocket fa-4x text-warning"></i>
            </div>
            <h3 class="fw-bold">Quase lá!</h3>
            <p class="text-muted">Para ativar o destaque do imóvel <strong><?= esc($property->titulo) ?></strong>, conclua o pagamento abaixo.</p>
            
            <div class="alert alert-info border-0 rounded-4 p-4 mb-4">
                <div class="small text-uppercase fw-bold mb-1">Valor a pagar</div>
                <div class="h2 fw-bold mb-0">R$ <?= number_format($package->preco, 2, ',', '.') ?></div>
            </div>

            <div class="d-grid gap-3">
                <a href="<?= $invoice_url ?>" target="_blank" class="btn btn-primary btn-lg rounded-pill py-3 fw-bold shadow">
                    <i class="fa-solid fa-external-link me-2"></i> Pagar no Asaas (Pix, Boleto, Cartão)
                </a>
                
                <a href="<?= site_url('admin/properties') ?>" class="btn btn-light rounded-pill py-2">
                    Voltar para Meus Imóveis
                </a>
            </div>

            <div class="mt-4 small text-muted">
                <i class="fa-solid fa-circle-info me-1"></i> Assim que o pagamento for confirmado, seu imóvel será turbinado automaticamente.
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        const paymentId = '<?= esc($payment_id) ?>';
        const propertyId = '<?= esc($property->id) ?>'; // Assuming $property is available
        const checkUrl = '<?= site_url("admin/promotions/check-status/") ?>' + paymentId;
        const successUrl = '<?= site_url("admin/properties") ?>';

        let checkInterval = setInterval(function() {
            $.get(checkUrl, function(data) {
                if (data.confirmed) {
                    clearInterval(checkInterval);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Pagamento Confirmado!',
                        text: 'Seu imóvel foi turbinado com sucesso!',
                        confirmButtonColor: '#344767',
                        timer: 3000,
                        timerProgressBar: true
                    }).then(() => {
                        window.location.href = successUrl;
                    });
                }
            });
        }, 5000); // Check every 5 seconds
    });
</script>
<?= $this->endSection() ?>
