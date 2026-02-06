<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Configurar <?= esc($gateway->name) ?><?= $this->endSection() ?>
<?= $this->section('page_title') ?>Ajustes do Gateway: <?= esc($gateway->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-key me-2 text-primary"></i>Credenciais e Parâmetros</h5>
                </div>
                <div class="card-body">
                    <form action="<?= site_url("admin/payment-gateways/update/{$gateway->id}") ?>" method="post">
                        <?= csrf_field() ?>

                        <?php if (empty($configs)): ?>
                            <div class="alert alert-info rounded-3 border-0">
                                <i class="fa-solid fa-info-circle me-2"></i> Este gateway não possui configurações adicionais.
                            </div>
                        <?php else: ?>
                            <div class="row g-4">
                                <?php foreach ($configs as $config): ?>
                                    <div class="col-md-6">
                                        <div class="form-group mb-2">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <label for="<?= esc($config->config_key) ?>" class="form-label small fw-bold text-muted text-uppercase ls-1 mb-0">
                                                    <?php
                                                    $labels = [
                                                        'api_key' => 'Chave de API',
                                                        'public_key' => 'Chave Pública',
                                                        'access_token' => 'Token de Acesso',
                                                        'webhook_secret' => 'Segredo do Webhook',
                                                        'environment' => 'Ambiente',
                                                        'merchant_id' => 'ID do Comerciante',
                                                        'secret_key' => 'Chave Secreta',
                                                        'publishable_key' => 'Chave Publicável'
                                                    ];
                                                    echo $labels[$config->config_key] ?? ucwords(str_replace(['_', '-'], ' ', $config->config_key));
                                                    ?>
                                                </label>
                                                <?php if ($config->is_sensitive): ?>
                                                    <span class="badge bg-light text-secondary border rounded-pill x-small" title="Campo Seguro (Criptografado)" data-bs-toggle="tooltip">
                                                        <i class="fas fa-lock me-1"></i> Seguro
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="input-group shadow-sm rounded-3 overflow-hidden border-0">
                                                <?php if ($config->config_type === 'select'): ?>
                                                    <span class="input-group-text bg-light border-0 ps-3"><i class="fa-solid fa-layer-group text-primary opacity-50"></i></span>
                                                    <select class="form-select border-0 bg-light" name="<?= esc($config->config_key) ?>" id="<?= esc($config->config_key) ?>" style="height: 45px;">
                                                        <?php if ($config->config_key === 'environment'): ?>
                                                            <option value="sandbox" <?= $config->config_value === 'sandbox' ? 'selected' : '' ?>>Sandbox (Testes)</option>
                                                            <option value="production" <?= $config->config_value === 'production' ? 'selected' : '' ?>>Produção (Real)</option>
                                                        <?php else: ?>
                                                            <option value="<?= esc($config->config_value) ?>" selected><?= esc($config->config_value) ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                <?php elseif ($config->config_type === 'boolean'): ?>
                                                    <div class="form-control border-0 bg-light p-0 pt-2 h-100 d-flex align-items-center ps-3" style="height: 45px;">
                                                        <div class="form-check form-switch custom-switch">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="<?= esc($config->config_key) ?>" 
                                                                   id="<?= esc($config->config_key) ?>" 
                                                                   value="1" 
                                                                   <?= $config->config_value === '1' || $config->config_value === true ? 'checked' : '' ?>>
                                                            <label class="form-check-label small fw-bold" for="<?= esc($config->config_key) ?>">HABILITAR RECURSO</label>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="input-group-text bg-light border-0 ps-3">
                                                        <i class="fa-solid <?= $config->is_sensitive ? 'fa-key' : 'fa-pen' ?> text-primary opacity-50"></i>
                                                    </span>
                                                    <input type="<?= $config->is_sensitive ? 'password' : 'text' ?>" 
                                                           class="form-control border-0 bg-light" 
                                                           name="<?= esc($config->config_key) ?>" 
                                                           id="<?= esc($config->config_key) ?>" 
                                                           value="<?= esc($config->config_value) ?>" 
                                                           placeholder="Digite o valor..."
                                                           style="height: 45px;">
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="form-text x-small opacity-50 mt-1 d-flex gap-2 align-items-center">
                                                <i class="fa-solid fa-code"></i> ID: <code class="text-muted"><?= esc($config->config_key) ?></code>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 pt-3 border-top d-flex gap-3">
                            <button type="submit" class="btn btn-primary rounded-pill px-5 shadow-sm py-2">
                                <i class="fa-solid fa-save me-2"></i> Salvar e Testar
                            </button>
                            <a href="<?= site_url('admin/payment-gateways') ?>" class="btn btn-light rounded-pill px-4 py-2">
                                Voltar
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 bg-primary text-white mb-4">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-white bg-opacity-25 rounded-circle p-2 me-3">
                            <i class="fa-solid fa-circle-info fs-5"></i>
                        </div>
                        <h6 class="fw-bold mb-0">Central de Ajuda</h6>
                    </div>
                    <p class="small opacity-75 mb-4">
                        Toda a configuração é feita com base nas credenciais oficiais do seu provedor de pagamentos.
                    </p>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex gap-3">
                            <i class="fa-solid fa-flask xsmall mt-1 mt-1"></i>
                            <div>
                                <h6 class="mb-1 xsmall fw-bold">Ambiente de Testes</h6>
                                <p class="xsmall mb-0 opacity-75">Use o Sandbox (homologação) antes de ir para produção.</p>
                            </div>
                        </div>
                        <div class="d-flex gap-3">
                            <i class="fa-solid fa-key xsmall mt-1"></i>
                            <div>
                                <h6 class="mb-1 xsmall fw-bold">Segurança de Dados</h6>
                                <p class="xsmall mb-0 opacity-75">Chaves sensíveis são criptografadas em nosso banco de dados.</p>
                            </div>
                        </div>
                        <div class="d-flex gap-3">
                            <i class="fa-solid fa-link xsmall mt-1"></i>
                            <div>
                                <h6 class="mb-1 xsmall fw-bold">Webhook</h6>
                                <p class="xsmall mb-0 opacity-75">Confirme se a URL de retorno está configurada no painel do gateway.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">Dashboard <?= esc($gateway->name) ?></h6>
                    <?php if ($gateway->code === 'asaas'): ?>
                        <a href="https://sandbox.asaas.com" target="_blank" class="btn btn-light w-100 rounded-3 mb-2 text-start small">
                            <i class="fa-solid fa-external-link me-2 text-muted"></i> Painel Sandbox
                        </a>
                        <a href="https://www.asaas.com" target="_blank" class="btn btn-light w-100 rounded-3 text-start small">
                            <i class="fa-solid fa-external-link me-2 text-muted"></i> Painel Produção
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
