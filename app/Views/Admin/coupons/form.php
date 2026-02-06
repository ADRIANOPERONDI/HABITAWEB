<?= $this->extend('Layouts/master') ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="mt-4 mb-4">
        <h1><?= isset($coupon) ? 'Editar' : 'Criar' ?> Cupom</h1>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form action="<?= isset($coupon) ? site_url('admin/coupons/update/' . $coupon->id) : site_url('admin/coupons/store') ?>" method="post">
                <?= csrf_field() ?>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label fw-bold">Código do Cupom</label>
                            <div class="input-group">
                                <input type="text" name="code" id="couponCode" class="form-control text-uppercase" placeholder="Ex: PROMOCAO10" value="<?= old('code', $coupon->code ?? '') ?>" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="generateCouponCode()">
                                    <i class="fas fa-magic"></i> Gerar
                                </button>
                            </div>
                            <small class="text-muted">Clique em "Gerar" para criar um código automaticamente</small>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Tipo de Desconto</label>
                        <select name="discount_type" class="form-select" required>
                            <option value="percent" <?= (old('discount_type', $coupon->discount_type ?? '') == 'percent') ? 'selected' : '' ?>>Porcentagem (%)</option>
                            <option value="fixed" <?= (old('discount_type', $coupon->discount_type ?? '') == 'fixed') ? 'selected' : '' ?>>Valor Fixo (R$)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Valor do Desconto</label>
                        <input type="number" step="0.01" name="discount_value" class="form-control" placeholder="10.00" value="<?= old('discount_value', $coupon->discount_value ?? '') ?>" required>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Limite de Usos (Opcional)</label>
                        <input type="number" name="max_uses" class="form-control" placeholder="Infinito" value="<?= old('max_uses', $coupon->max_uses ?? '') ?>">
                        <small class="text-muted">Deixe em branco para ilimitado.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Validade (Opcional)</label>
                        <input type="date" name="valid_until" class="form-control" value="<?= old('valid_until', isset($coupon->valid_until) ? date('Y-m-d', strtotime($coupon->valid_until)) : '') ?>">
                    </div>
                </div>
                
                <div class="mb-4">
                     <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" <?= (!isset($coupon) || $coupon->is_active) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">Cupom Ativo</label>
                    </div>
                </div>

                <a href="<?= site_url('admin/coupons') ?>" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar Cupom</button>
            </form>
        </div>
    </div>
</div>

<script>
function generateCouponCode() {
    const patterns = [
        'PROMO',
        'DESCONTO',
        'OFERTA',
        'WELCOME',
        'NEWCLIENT',
        'ESPECIAL'
    ];
    
    const pattern = patterns[Math.floor(Math.random() * patterns.length)];
    const randomNum = Math.floor(Math.random() * 900) + 100; // 100-999
    const months = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];
    const currentMonth = months[new Date().getMonth()];
    const currentYear = new Date().getFullYear();
    
    // Gerar código com diferentes formatos aleatórios
    const formats = [
        `${pattern}${randomNum}`,
        `${pattern}${currentMonth}${randomNum}`,
        `${pattern}${currentYear}`,
        `${currentMonth}${randomNum}`,
        `${pattern}${randomNum}OFF`
    ];
    
    const code = formats[Math.floor(Math.random() * formats.length)];
    document.getElementById('couponCode').value = code.toUpperCase();
}
</script>

<?= $this->endSection() ?>
