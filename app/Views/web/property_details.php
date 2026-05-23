<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?><?= esc($property->titulo) ?><?= $this->endSection() ?>

<?= $this->section('meta_title') ?><?= esc($property->getMetaTitle()) ?><?= $this->endSection() ?>
<?= $this->section('meta_description') ?><?= esc($property->getMetaDescription()) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="public-shell">
<div class="container py-5">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item"><a href="<?= site_url('/') ?>" class="text-decoration-none">Home</a></li>
            <li class="breadcrumb-item"><a href="<?= site_url('imoveis') ?>" class="text-decoration-none">Busca</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= esc($property->titulo) ?></li>
        </ol>
    </nav>

    <div class="row g-5">
        <!-- Gallery & Main Info -->
        <div class="col-lg-8">
            <div class="card border-0 overflow-hidden mb-4" style="border-radius: 12px; box-shadow: var(--premium-shadow-sm);">
                <div id="propertyCarousel" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-inner" style="aspect-ratio: 16 / 9; background: #f8f9fa;">
                        <?php if(empty($medias)): ?>
                             <div class="carousel-item active">
                                <img src="https://placehold.co/1200x600?text=Sem+Foto" class="d-block w-100 object-fit-cover" alt="Sem foto">
                            </div>
                        <?php else: ?>
                            <!-- THEATER CAROUSEL V2 -->
                            <?php foreach($medias as $index => $media): 
                                $imgUrl = !empty($media->url) ? (strpos($media->url, 'http') === 0 ? $media->url : base_url($media->url)) : base_url('assets/img/placeholder-house.png');
                            ?>
                            <div class="carousel-item h-100 <?= $index === 0 ? 'active' : '' ?>" style="overflow: hidden;">
                                <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: url('<?= $imgUrl ?>') center/cover; filter: blur(15px); opacity: 0.5; transform: scale(1.1);"></div>
                                <img src="<?= $imgUrl ?>" class="d-block w-100 h-100 position-relative" style="z-index: 1; object-fit: contain;" alt="Foto do imóvel">
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if(count($medias) > 1): ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#propertyCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Anterior</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#propertyCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Próximo</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-3">
                    <h2 class="premium-title mb-0"><?= esc($property->titulo) ?></h2>
                    <?php if($property->is_verified): ?>
                        <span class="badge bg-info text-white rounded-pill px-3 py-2 fw-bold" style="background-color: #0d6efd !important;" title="Este imóvel passou por conferência documental e de autenticidade.">
                            <i class="fa-solid fa-check-double me-1"></i> Verificado
                        </span>
                    <?php endif; ?>
                </div>
                <button class="btn btn-outline-danger rounded-circle border-0 btn-favorite p-2" data-id="<?= $property->id ?>" title="Favoritar">
                   <i class="<?= ($isFavorited ?? false) ? 'fa-solid text-danger' : 'fa-regular' ?> fa-heart fa-2x"></i>
                </button>
            </div>
            <div class="text-muted mb-4 fs-5">
                <i class="fa-solid fa-location-dot text-primary me-2"></i> <?= esc($property->bairro) ?>, <?= esc($property->cidade) ?>
            </div>

            <div class="row g-4 mb-5">
                <div class="col-6 col-md-2 text-center">
                    <div class="p-3 bg-white border" style="border-radius: 8px;">
                        <i class="fa-solid fa-ruler-combined fa-2x text-primary mb-2"></i>
                        <h5 class="fw-bold mb-0"><?= $property->area_total ?> m²</h5>
                        <small class="text-muted">Área</small>
                    </div>
                </div>
                <div class="col-6 col-md-2 text-center">
                    <div class="p-3 bg-white border" style="border-radius: 8px;">
                        <i class="fa-solid fa-bed fa-2x text-primary mb-2"></i>
                        <h5 class="fw-bold mb-0"><?= $property->quartos ?></h5>
                        <small class="text-muted">Quartos</small>
                    </div>
                </div>
                <?php if($property->suites): ?>
                <div class="col-6 col-md-2 text-center">
                    <div class="p-3 bg-white border" style="border-radius: 8px;">
                        <i class="fa-solid fa-person-shelter fa-2x text-primary mb-2"></i>
                        <h5 class="fw-bold mb-0"><?= $property->suites ?></h5>
                        <small class="text-muted">Suítes</small>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-2 text-center">
                    <div class="p-3 bg-white border" style="border-radius: 8px;">
                        <i class="fa-solid fa-bath fa-2x text-primary mb-2"></i>
                        <h5 class="fw-bold mb-0"><?= $property->banheiros ?></h5>
                        <small class="text-muted">Banh.</small>
                    </div>
                </div>
                 <div class="col-6 col-md-2 text-center">
                    <div class="p-3 bg-white border" style="border-radius: 8px;">
                        <i class="fa-solid fa-car fa-2x text-primary mb-2"></i>
                        <h5 class="fw-bold mb-0"><?= $property->vagas ?></h5>
                        <small class="text-muted">Vagas</small>
                    </div>
                </div>
            </div>

            <h4 class="fw-bold mb-3">Comodidades & Diferenciais</h4>
            <div class="row g-3 mb-5">
                <?php if($property->aceita_pets): ?>
                    <div class="col-6 col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                            <i class="fa-solid fa-paw text-primary me-2"></i> Aceita Pets
                        </div>
                    </div>
                <?php endif; ?>
                <?php if($property->mobiliado): ?>
                    <div class="col-6 col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                            <i class="fa-solid fa-couch text-primary me-2"></i> Mobiliado
                        </div>
                    </div>
                <?php elseif($property->semimobiliado): ?>
                    <div class="col-6 col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                            <i class="fa-solid fa-chair text-primary me-2"></i> Semimobiliado
                        </div>
                    </div>
                <?php endif; ?>
                <?php if($property->is_desocupado): ?>
                    <div class="col-6 col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                            <i class="fa-solid fa-house-circle-check text-primary me-2"></i> Desocupado
                        </div>
                    </div>
                <?php endif; ?>
                <?php if($property->indicado_investidor): ?>
                    <div class="col-6 col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                            <i class="fa-solid fa-chart-line text-primary me-2"></i> Ideal Investidor
                        </div>
                    </div>
                <?php endif; ?>
                <?php if($property->is_destaque || (isset($property->highlight_level) && $property->highlight_level > 0)): ?>
                    <div class="col-6 col-md-4">
                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small bg-warning-light">
                            <i class="fa-solid fa-crown text-warning me-2"></i> Anúncio Patrocinado
                        </div>
                    </div>
                <?php endif; ?>
                <?php if($property->is_novo): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                                            <i class="fa-solid fa-sparkles text-primary me-2"></i> Imóvel Novo
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if($property->is_exclusivo): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                                            <i class="fa-solid fa-shield-halved text-primary me-2" style="color: #6f42c1 !important;"></i> Exclusivo
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if($property->is_locado): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                                            <i class="fa-solid fa-key text-warning me-2"></i> Atualmente Locado
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if($property->indicado_primeira_moradia): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                                            <i class="fa-solid fa-heart text-danger me-2"></i> Primeira Moradia
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if($property->indicado_temporada): ?>
                                    <div class="col-6 col-md-4">
                                        <div class="d-flex align-items-center p-2 border rounded-3 text-muted x-small">
                                            <i class="fa-solid fa-umbrella-beach text-info me-2"></i> Temporada
                                        </div>
                                    </div>
                                <?php endif; ?>
            </div>

            <h4 class="fw-bold mb-3">Descrição</h4>
            <div class="text-muted lh-lg mb-5">
                <?= $property->descricao ?>
            </div>

            <h4 class="fw-bold mb-3">Outras Características</h4>
            <div class="row g-3 mb-5">
                <?php if(empty($features)): ?>
                    <p class="text-muted small">Nenhuma outra característica cadastrada.</p>
                <?php else: ?>
                    <?php foreach($features as $feature): ?>
                        <div class="col-md-4">
                            <i class="fa-solid fa-check text-success me-2"></i> 
                            <?= esc(\App\Helpers\PropertyCategoryHelper::getFeatureLabel($feature->chave)) ?> 
                            <?php if($feature->valor && $feature->valor != '1'): ?>
                                <span class="text-muted small">(<?= esc($feature->valor) ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if($property->latitude && $property->longitude): ?>
            <h4 class="fw-bold mb-3 mt-5">Localização</h4>
            <div class="card border-0 shadow-sm overflow-hidden mb-5" style="border-radius: 12px;">
                <iframe 
                    width="100%" 
                    height="400" 
                    style="border:0" 
                    loading="lazy" 
                    allowfullscreen 
                    src="https://maps.google.com/maps?q=<?= $property->latitude ?>,<?= $property->longitude ?>&z=15&output=embed">
                </iframe>
            </div>
            <?php elseif($property->rua && $property->cidade): ?>
            <h4 class="fw-bold mb-3 mt-5">Localização</h4>
            <div class="card border-0 shadow-sm overflow-hidden mb-5" style="border-radius: 12px;">
                <iframe 
                    width="100%" 
                    height="400" 
                    style="border:0" 
                    loading="lazy" 
                    allowfullscreen 
                    src="https://maps.google.com/maps?q=<?= urlencode($property->rua . ', ' . $property->numero . ' - ' . $property->bairro . ', ' . $property->cidade . ' - ' . $property->estado) ?>&z=15&output=embed">
                </iframe>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Contact -->
        <div class="col-lg-4">
            <div class="card border-0 p-4 sticky-top" style="top: 100px; border-radius: 12px; box-shadow: var(--premium-shadow);">
                <div class="premium-eyebrow"><?= $property->tipo_negocio === 'VENDA' ? 'Compra' : 'Aluguel' ?></div>
                <h3 class="fw-bold text-dark mb-1">R$ <?= number_format($property->preco, 2, ',', '.') ?></h3>
                <?php if($property->tipo_negocio === 'ALUGUEL'): ?>
                    <p class="text-muted">/mês</p>
                <?php endif; ?>

                <div class="d-flex flex-column gap-1 mb-4 x-small text-muted">
                    <?php if($property->valor_condominio > 0): ?>
                        <span>Condomínio: <strong>R$ <?= number_format($property->valor_condominio, 2, ',', '.') ?></strong></span>
                    <?php endif; ?>
                    <?php if($property->iptu > 0): ?>
                        <span>IPTU Anual: <strong>R$ <?= number_format($property->iptu, 2, ',', '.') ?></strong></span>
                    <?php endif; ?>
                </div>

                <hr class="my-4">

                <h5 class="fw-bold mb-3">Responsável pela Oferta</h5>
                
                <div class="mb-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="flex-shrink-0">
                            <?php if($property->account_logo): ?>
                                <img src="<?= base_url($property->account_logo) ?>" class="rounded-3 shadow-sm object-fit-contain bg-white" width="64" height="64">
                            <?php else: ?>
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($property->account_name) ?>&background=random" class="rounded-circle" width="60" height="60">
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="fw-bold text-dark fs-5 d-flex align-items-center gap-2">
                                <?= esc($property->account_name) ?>
                                <?php if($property->account_verified): ?>
                                    <i class="fa-solid fa-circle-check text-primary fs-6" title="Parceiro Verificado"></i>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted text-uppercase fw-bold opacity-75" style="font-size: 10px;"><?= esc($property->account_type) ?></small>
                            <?php if($property->account_creci): ?>
                                <div class="x-small text-muted">CRECI: <strong><?= esc($property->account_creci) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-light p-3 rounded-4 x-small text-muted">
                        <?php if($property->account_email): ?>
                            <div class="mb-1"><i class="fa-solid fa-envelope me-2"></i> <?= esc($property->account_email) ?></div>
                        <?php endif; ?>
                        <div class="mb-1"><i class="fa-solid fa-phone me-2"></i> <?= esc($property->account_phone ?? $property->account_whatsapp ?? '(11) 99999-9999') ?></div>
                        <a href="<?= site_url('parceiro/' . $property->account_id) ?>" class="text-primary fw-bold text-decoration-none d-block mt-2">
                            Ver Perfil Completo <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>

                <?php
                    $hasPhotos = !empty($medias);
                    $hasLocation = !empty($property->latitude) && !empty($property->longitude);
                    $hasDescription = !empty(trim((string) ($property->descricao ?? '')));
                    $isComplete = $hasPhotos && $hasLocation && $hasDescription;
                ?>
                <div class="border rounded-4 p-3 mb-4 bg-white">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white" style="width: 32px; height: 32px;">
                            <i class="fa-solid fa-shield-halved"></i>
                        </span>
                        <div>
                            <div class="fw-bold text-dark">Por que confiar neste anúncio?</div>
                            <div class="x-small text-muted">Sinais verificados pelo portal antes do contato.</div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php if($property->is_verified): ?>
                            <span class="badge rounded-pill text-bg-primary"><i class="fa-solid fa-check-double me-1"></i> Imóvel verificado</span>
                        <?php endif; ?>
                        <?php if($property->account_verified): ?>
                            <span class="badge rounded-pill text-bg-success"><i class="fa-solid fa-circle-check me-1"></i> Anunciante verificado</span>
                        <?php endif; ?>
                        <?php if($isComplete): ?>
                            <span class="badge rounded-pill text-bg-light border text-dark"><i class="fa-solid fa-list-check me-1"></i> Anúncio completo</span>
                        <?php endif; ?>
                        <span class="badge rounded-pill text-bg-light border text-dark"><i class="fa-solid fa-headset me-1"></i> Atendimento rastreado</span>
                    </div>
                    <?php if(!$hasPhotos || !$hasLocation || !$hasDescription): ?>
                        <div class="alert alert-warning py-2 px-3 mb-3 x-small">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            Este anúncio ainda tem informações pendentes. Confirme fotos, localização e detalhes com o anunciante.
                        </div>
                    <?php endif; ?>
                    <div class="x-small text-muted">
                        Nunca faça pagamento antecipado sem visitar o imóvel, validar o responsável e conferir documentos básicos.
                    </div>
                </div>

                <form id="leadForm">
                    <input type="hidden" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>">
                    <input type="hidden" name="property_id" value="<?= $property->id ?>">
                    <div class="mb-3">
                        <input type="text" name="nome_visitante" class="form-control" placeholder="Seu nome" required>
                    </div>
                    <div class="mb-3">
                        <input type="email" name="email_visitante" class="form-control" placeholder="Seu e-mail" required>
                    </div>
                     <div class="mb-3">
                        <input type="tel" name="telefone_visitante" class="form-control" placeholder="Seu telefone" required>
                    </div>
                    <div class="mb-3">
                        <textarea name="mensagem" class="form-control" rows="3" placeholder="Mensagem">Olá, gostaria de mais informações sobre este imóvel.</textarea>
                    </div>
                    <button type="button" id="btnWhatsAppHub" class="btn btn-success w-100 btn-lg fw-bold rounded-pill mb-3 shadow-sm">
                        <i class="fa-brands fa-whatsapp me-2"></i> Falar no WhatsApp
                    </button>
                    <button type="submit" id="btnLead" class="btn btn-outline-primary w-100 btn-md fw-bold rounded-pill">
                        <i class="fa-solid fa-envelope me-2"></i> Quero receber contato
                    </button>
                    <div id="leadFeedback" class="mt-3 text-center d-none"></div>
                </form>

                <script>
                const leadEndpoint = new URL('<?= site_url('leads') ?>', window.location.origin).pathname;
                const csrfFieldName = '<?= csrf_token() ?>';

                function updateLeadCsrf(data) {
                    if (!data || !data.csrf_hash) return;
                    const csrfInput = document.querySelector(`#leadForm input[name="${csrfFieldName}"]`);
                    if (csrfInput) {
                        csrfInput.value = data.csrf_hash;
                        csrfInput.setAttribute('value', data.csrf_hash);
                    }
                }

                document.getElementById('leadForm').addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const btn = document.getElementById('btnLead');
                    const feedback = document.getElementById('leadFeedback');
                    const originalText = btn.innerHTML;
                    
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
                    feedback.classList.add('d-none');
                    feedback.className = 'mt-3 text-center d-none'; // reset classes
                    
                    const formData = new FormData(this);
                    
                    fetch(leadEndpoint, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        feedback.classList.remove('d-none');
                        if(data.success) {
                            feedback.classList.add('text-success', 'fw-bold');
                            feedback.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + data.message;
                            this.reset();
                            updateLeadCsrf(data);
                        } else {
                            feedback.classList.add('text-danger');
                            let msg = data.message || 'Erro ao enviar.';
                            if(data.errors) {
                                msg = Object.values(data.errors).join('<br>');
                            }
                            feedback.innerHTML = msg;
                        }
                        updateLeadCsrf(data);
                    })
                    .catch(error => {
                        feedback.classList.remove('d-none');
                        feedback.classList.add('text-danger');
                        feedback.innerHTML = 'Erro de conexão/servidor.';
                        console.error(error);
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
                });
                </script>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal WhatsApp Hub (Linktree Style) -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 pt-0 text-center">
                <div class="mb-4">
                    <?php if($property->account_logo): ?>
                        <img src="<?= base_url($property->account_logo) ?>" class="rounded-3 shadow-sm mb-3 object-fit-contain bg-white" width="80" height="80">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($property->account_name) ?>&background=random" class="rounded-circle mb-3" width="70" height="70">
                    <?php endif; ?>
                    <h5 class="fw-bold mb-1"><?= esc($property->account_name) ?></h5>
                    <p class="text-muted small">Escolha como prefere ser atendido no WhatsApp:</p>
                </div>

                <div class="d-grid gap-3" id="whatsappButtonsContainer">
                    <!-- Botões dinâmicos via JS -->
                </div>
                
                <p class="mt-4 text-muted x-small">
                    Relatamos o clique para controle de qualidade e métricas do anunciante.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
const whatsappConfig = <?= json_encode($property->whatsapp_hub_config ?? []) ?>;
const leadEventEndpoint = new URL('<?= site_url('leads/register-event') ?>', window.location.origin).pathname;
const propertyInfo = {
    id: "<?= $property->id ?>",
    titulo: "<?= esc($property->titulo) ?>",
    cidade: "<?= esc($property->cidade) ?>",
    bairro: "<?= esc($property->bairro) ?>",
    preco: "<?= number_format($property->preco, 2, ',', '.') ?>",
    operacao: "<?= $property->tipo_negocio === 'VENDA' ? 'Comprar' : 'Alugar' ?>",
    url: "<?= current_url() ?>"
};

const defaultWhatsApp = "<?= esc($property->account_whatsapp ?? $property->account_phone ?? '') ?>";

document.getElementById('btnWhatsAppHub').addEventListener('click', function() {
    // Se houver múltiplos números, abre o seletor. Senão vai direto.
    if (whatsappConfig && Array.isArray(whatsappConfig) && whatsappConfig.length > 1) {
        showWhatsAppSelectionModal();
    } else {
        handleWhatsAppClick(defaultWhatsApp, 'Atendimento');
    }
});

function showWhatsAppSelectionModal() {
    const container = document.getElementById('whatsappButtonsContainer');
    container.innerHTML = '';

    const buttons = (whatsappConfig && Array.isArray(whatsappConfig) && whatsappConfig.length > 0) ? whatsappConfig : [
        { name: 'Falar com Atendimento', number: defaultWhatsApp }
    ];

    buttons.forEach((btn, index) => {
        const button = document.createElement('a');
        button.href = '#';
        button.className = 'btn btn-outline-success btn-lg rounded-pill fw-bold py-3 d-flex align-items-center justify-content-center';
        button.innerHTML = `<i class="fa-brands fa-whatsapp fa-xl me-3"></i> ${btn.name}`;
        button.addEventListener('click', (e) => {
            e.preventDefault();
            handleWhatsAppClick(btn.number, btn.name);
        });
        container.appendChild(button);
    });

    const modal = new bootstrap.Modal(document.getElementById('whatsappModal'));
    modal.show();
}

const whatsappMessagesConfig = <?= json_encode($property->whatsapp_messages_config ?? []) ?>;

function handleWhatsAppClick(number, channelName) {
    if (!number) return;
    
    // 1. Gera a mensagem padrão baseada no SOW ou template customizado
    let msgTemplate = whatsappMessagesConfig[propertyInfo.operacao.toUpperCase()] || 
                      whatsappMessagesConfig['DEFAULT'] ||
                      "Olá! Tenho interesse no imóvel [#{id}] em {bairro}/{cidade} ({operacao}). Valor: R$ {preco}. Link: {url}. Podemos agendar uma visita?";

    // Substitui variáveis no template (sem lead_ref fixo agora para ser instantâneo)
    const msg = msgTemplate
        .replace('{id}', propertyInfo.id)
        .replace('{lead_ref}', '') // Removido para evitar fetch bloqueante
        .replace('{bairro}', propertyInfo.bairro)
        .replace('{cidade}', propertyInfo.cidade)
        .replace('{operacao}', propertyInfo.operacao)
        .replace('{preco}', propertyInfo.preco)
        .replace('{url}', propertyInfo.url);
    
    const waUrl = `https://api.whatsapp.com/send?phone=${number.replace(/\D/g, '')}&text=${encodeURIComponent(msg)}`;

    // 2. Redireciona IMEDIATAMENTE (Síncrono para evitar pop-up blocker)
    window.open(waUrl, '_blank');

    // 3. Registra o lead em background (Fire and forget)
    const formData = new FormData();
    const csrfInput = document.querySelector(`#leadForm input[name="${csrfFieldName}"]`);
    if (csrfInput) {
        formData.append(csrfFieldName, csrfInput.value);
    }
    formData.append('property_id', propertyInfo.id);
    formData.append('evento', 'whatsapp_click');
    formData.append('nome_visitante', document.querySelector('#leadForm [name="nome_visitante"]')?.value || '');
    formData.append('email_visitante', document.querySelector('#leadForm [name="email_visitante"]')?.value || '');
    formData.append('telefone_visitante', document.querySelector('#leadForm [name="telefone_visitante"]')?.value || '');
    formData.append('payload', JSON.stringify({ channel: channelName, number: number, source: 'property_details' }));

    fetch(leadEventEndpoint, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(updateLeadCsrf)
    .catch(error => console.warn('Lead WhatsApp tracking failed', error));
}
</script>
<?= $this->endSection() ?>
