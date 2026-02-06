<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?><?= isset($client) ? 'Editar' : 'Novo' ?> Cliente<?= $this->endSection() ?>
<?= $this->section('page_title') ?><?= isset($client) ? 'Editar Cliente' : 'Novo Cliente' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        
        <a href="<?= site_url('admin/clients') ?>" class="btn btn-link text-decoration-none mb-3 ps-0 text-muted">
            <i class="fa-solid fa-arrow-left me-1"></i> Voltar para lista
        </a>

        <div class="card card-premium">
            <div class="card-body p-4">
                <?php $action = isset($client) ? site_url('admin/clients/' . $client->id) : site_url('admin/clients') ?>
                <form action="<?= $action ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if(isset($client)): ?>
                        <input type="hidden" name="_method" value="PUT">
                    <?php endif; ?>

                    <div class="row g-4">
                        <?php if(!empty($accounts)): ?>
                        <div class="col-12">
                            <label class="form-label-premium">Conta Vinculada (Admin)</label>
                            <select name="account_id" class="form-select input-premium select2" required>
                                <option value="">Selecione uma conta...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?= $acc->id ?>" <?= (old('account_id', $client->account_id ?? '') == $acc->id) ? 'selected' : '' ?>>
                                        <?= esc($acc->nome) ?> (ID: <?= $acc->id ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label class="form-label-premium">Nome Completo / Razão Social</label>
                            <input type="text" name="nome" class="form-control input-premium" required value="<?= old('nome', $client->nome ?? '') ?>" placeholder="Ex: João da Silva">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-premium">E-mail</label>
                            <input type="email" name="email" class="form-control input-premium" value="<?= old('email', $client->email ?? '') ?>" placeholder="joao@exemplo.com">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-premium">Telefone / WhatsApp</label>
                            <input type="text" name="telefone" class="form-control input-premium" value="<?= old('telefone', $client->telefone ?? '') ?>" placeholder="(00) 00000-0000">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-premium">CPF / CNPJ</label>
                            <input type="text" name="cpf_cnpj" class="form-control input-premium" value="<?= old('cpf_cnpj', $client->cpf_cnpj ?? '') ?>" placeholder="000.000.000-00">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-premium">Tipo de Cliente</label>
                            <select name="tipo_cliente" class="form-select input-premium select2">
                                <option value="PROPRIETARIO" <?= (old('tipo_cliente', $client->tipo_cliente ?? '') == 'PROPRIETARIO') ? 'selected' : '' ?>>Proprietário</option>
                                <option value="INTERESSADO" <?= (old('tipo_cliente', $client->tipo_cliente ?? '') == 'INTERESSADO') ? 'selected' : '' ?>>Interessado (Lead)</option>
                                <option value="OUTRO" <?= (old('tipo_cliente', $client->tipo_cliente ?? '') == 'OUTRO') ? 'selected' : '' ?>>Outro</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label-premium">Notas Internas</label>
                            <textarea name="notas" class="form-control input-premium" rows="4" placeholder="Observações sobre o cliente..."><?= old('notas', $client->notas ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-5 gap-2">
                        <a href="<?= site_url('admin/clients') ?>" class="btn btn-light rounded-pill px-4 border">Cancelar</a>
                        <button type="submit" class="btn btn-primary rounded-pill px-5">
                            <i class="fa-solid fa-save me-2"></i> Salvar Cliente
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
