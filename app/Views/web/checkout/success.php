<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 text-center p-4">
                <div class="card-body">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                    </div>
                    
                    <h3 class="fw-bold mb-3">Pedido Realizado!</h3>
                    <p class="text-muted mb-4">
                        Sua assinatura foi criada com sucesso. <br>
                        Para ativar, realize o pagamento abaixo.
                    </p>
                    
                    <div class="bg-light p-3 rounded-4 mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">ID do Pedido</span>
                            <span class="fw-bold">#<?= esc($local_id ?? 'N/A') ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Valor</span>
                            <span class="fw-bold text-success">R$ <?= number_format($subscription['value'] ?? 0, 2, ',', '.') ?></span>
                        </div>
                    </div>

                    <?php if (($subscription['billingType'] ?? '') === 'PIX'): ?>
                        <!-- PIX AREA -->
                        <div class="alert alert-info border-0 rounded-4">
                            <h6 class="fw-bold mb-2"><i class="fas fa-qrcode me-2"></i> Pague com PIX</h6>
                            <p class="small mb-3">Escaneie o QR Code ou copie a chave abaixo.</p>
                            
                            <?php 
                                $qrCode = $subscription['first_payment']['pixQrCode'] ?? null;
                                if($qrCode): 
                            ?>
                                <img src="data:image/png;base64,<?= $qrCode['encodedImage'] ?>" class="img-fluid mb-3 rounded" style="max-width: 200px;">
                                
                                <div class="input-group mb-2">
                                    <input type="text" class="form-control" value="<?= esc($qrCode['payload']) ?>" id="pixCopy" readonly>
                                    <button class="btn btn-outline-primary" type="button" onclick="copyPix()">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Código Expira em breve</small>
                            <?php else: ?>
                                <p class="text-danger small">QR Code indisponível no momento. Verifique seu email.</p>
                            <?php endif; ?>
                        </div>

                    <?php elseif (($subscription['billingType'] ?? '') === 'BOLETO'): ?>
                        <!-- BOLETO AREA -->
                         <div class="alert alert-warning border-0 rounded-4">
                            <h6 class="fw-bold mb-2"><i class="fas fa-barcode me-2"></i> Boleto Bancário</h6>
                            <p class="small mb-3">Seu boleto foi gerado. Clique abaixo para abrir.</p>
                            
                            <?php 
                                $bankSlipUrl = $subscription['first_payment']['bankSlipUrl'] ?? null;
                                if($bankSlipUrl): 
                            ?>
                                <a href="<?= $bankSlipUrl ?>" target="_blank" class="btn btn-primary rounded-pill w-100 mb-2">
                                    <i class="fas fa-file-pdf me-2"></i> Baixar Boleto
                                </a>
                                <p class="small text-muted mb-0">Linha digitável disponível no link.</p>
                            <?php else: ?>
                                <p class="text-danger small">Boleto indisponível no momento. Verifique seu email.</p>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <!-- Credit Card -->
                        <div class="alert alert-success border-0 rounded-4">
                            <h6 class="fw-bold mb-2"><i class="fas fa-credit-card me-2"></i> Pagamento em Processamento</h6>
                            <p class="small mb-0">Estamos processando seu cartão. Você receberá a confirmação em instantes.</p>
                        </div>
                    <?php endif; ?>
                    
                    <hr class="my-4">
                    
                    <a href="<?= site_url('admin/dashboard') ?>" class="btn btn-outline-dark rounded-pill px-4">
                        Ir para o Painel
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyPix() {
    var copyText = document.getElementById("pixCopy");
    copyText.select();
    copyText.setSelectionRange(0, 99999); 
    navigator.clipboard.writeText(copyText.value);
    alert("Código PIX copiado!");
}
</script>

<?= $this->endSection() ?>
