<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Meus Leads<?= $this->endSection() ?>

<?= $this->section('page_title') ?>Leads Recebidos<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4 animate-fade-in">
    <div>
        <h4 class="fw-bold mb-1">Interessados & Conversões</h4>
        <p class="text-muted small mb-0">Contatos recebidos através dos seus anúncios.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary rounded-pill px-4" type="button" data-bs-toggle="modal" data-bs-target="#exportModal">
            <i class="fa-solid fa-download me-2"></i> Exportar
        </button>
    </div>
</div>

<?php
    $stats = $stats ?? ['total' => 0, 'today' => 0, 'new' => 0, 'in_progress' => 0, 'closed' => 0, 'lost' => 0, 'answer_rate' => 0];
    $filters = $filters ?? [];
    $statusLabels = [
        'NOVO' => 'Novo',
        'EM_ATENDIMENTO' => 'Em atendimento',
        'CONCLUIDO' => 'Concluído',
        'PERDIDO' => 'Perdido',
    ];
?>

<div class="row g-3 mb-4 animate-fade-in" style="animation-delay: 0.05s">
    <div class="col-6 col-xl-3">
        <div class="card card-premium border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-bold text-uppercase">Leads hoje</div>
                <div class="h3 fw-bold mb-0"><?= (int) $stats['today'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-bold text-uppercase">Novos</div>
                <div class="h3 fw-bold mb-0"><?= (int) $stats['new'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-bold text-uppercase">Em atendimento</div>
                <div class="h3 fw-bold mb-0"><?= (int) $stats['in_progress'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card card-premium border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-bold text-uppercase">Taxa de resposta</div>
                <div class="h3 fw-bold mb-0"><?= (int) $stats['answer_rate'] ?>%</div>
            </div>
        </div>
    </div>
</div>

<div class="card card-premium border-0 mb-4 animate-fade-in" style="animation-delay: 0.08s">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-lg-3">
                <div class="small text-muted fw-bold mb-1">Novo</div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar bg-primary" style="width: <?= $stats['total'] > 0 ? max(4, (int) round($stats['new'] / $stats['total'] * 100)) : 0 ?>%"></div>
                </div>
                <div class="fw-bold mt-1"><?= (int) $stats['new'] ?></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small text-muted fw-bold mb-1">Em atendimento</div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar bg-info" style="width: <?= $stats['total'] > 0 ? max(4, (int) round($stats['in_progress'] / $stats['total'] * 100)) : 0 ?>%"></div>
                </div>
                <div class="fw-bold mt-1"><?= (int) $stats['in_progress'] ?></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small text-muted fw-bold mb-1">Concluído</div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar bg-success" style="width: <?= $stats['total'] > 0 ? max(4, (int) round($stats['closed'] / $stats['total'] * 100)) : 0 ?>%"></div>
                </div>
                <div class="fw-bold mt-1"><?= (int) $stats['closed'] ?></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="small text-muted fw-bold mb-1">Perdido</div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar bg-secondary" style="width: <?= $stats['total'] > 0 ? max(4, (int) round($stats['lost'] / $stats['total'] * 100)) : 0 ?>%"></div>
                </div>
                <div class="fw-bold mt-1"><?= (int) $stats['lost'] ?></div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="card card-premium border-0 mb-4 animate-fade-in" style="animation-delay: 0.1s">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="small text-muted fw-bold mb-1">Status</label>
                <select name="status" class="form-select rounded-pill">
                    <option value="">Todos</option>
                    <?php foreach($statusLabels as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="small text-muted fw-bold mb-1">Origem</label>
                <select name="origem" class="form-select rounded-pill">
                    <option value="">Todas</option>
                    <option value="SITE" <?= ($filters['origem'] ?? '') === 'SITE' ? 'selected' : '' ?>>Site</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="small text-muted fw-bold mb-1">Cidade</label>
                <input type="text" name="cidade" class="form-control rounded-pill" value="<?= esc($filters['cidade'] ?? '') ?>" placeholder="Cidade">
            </div>
            <div class="col-md-3">
                <label class="small text-muted fw-bold mb-1">Busca</label>
                <input type="search" name="q" class="form-control rounded-pill" value="<?= esc($filters['q'] ?? '') ?>" placeholder="Nome, e-mail, telefone ou imóvel">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary rounded-pill flex-grow-1" type="submit"><i class="fa-solid fa-filter me-1"></i> Filtrar</button>
                <a href="<?= site_url('admin/leads') ?>" class="btn btn-light rounded-circle border" title="Limpar"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </div>
</form>

<div class="card card-premium overflow-hidden border-0 animate-fade-in" style="animation-delay: 0.1s">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Data</th>
                        <th>Visitante</th>
                        <?php if($isAdmin): ?>
                        <th>Anunciante</th>
                        <?php endif; ?>
                        <th>Contato</th>
                        <th>Imóvel</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($leads)): ?>
                        <tr>
                            <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center py-5">
                                <i class="fa-regular fa-comment-dots fa-3x text-muted mb-3 d-block"></i>
                                <p class="text-muted">Nenhum lead recebido ainda.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($leads as $lead): ?>
                        <tr>
                            <td class="ps-4">
                                <?php if($lead->created_at): ?>
                                    <span class="fw-bold text-dark"><?= $lead->created_at->format('d/m') ?></span><br>
                                    <small class="text-muted"><?= $lead->created_at->format('H:i') ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($lead->nome_visitante) ?>&background=eef2ff&color=4f46e5" class="rounded-circle" width="32">
                                    <div>
                                        <div class="fw-bold text-dark"><?= esc($lead->nome_visitante) ?></div>
                                        <div class="small text-muted" style="font-size: 0.7rem;"><?= esc($lead->email_visitante) ?></div>
                                    </div>
                                </div>
                            </td>
                            <?php if($isAdmin): ?>
                            <td>
                                <div class="fw-bold text-dark small"><?= esc($lead->advertiser_name ?? 'N/A') ?></div>
                                <span class="badge bg-primary-soft small"><?= esc($lead->advertiser_type ?? 'N/A') ?></span>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php $phoneDigits = preg_replace('/\D/', '', $lead->telefone_visitante ?? ''); ?>
                                <?php if($phoneDigits): ?>
                                    <a href="https://wa.me/55<?= $phoneDigits ?>" target="_blank" class="btn btn-sm btn-light rounded-pill px-3 border">
                                        <i class="fa-brands fa-whatsapp text-tertiary me-1"></i> WhatsApp
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Sem telefone</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="javascript:void(0)" class="text-decoration-none view-property-preview" data-id="<?= $lead->id ?>">
                                    <span class="badge bg-light text-primary border border-primary-soft">ID #<?= $lead->property_id ?></span>
                                </a>
                                <?php if(!empty($lead->property_title)): ?>
                                    <div class="small text-muted mt-1"><?= esc($lead->property_title) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm rounded-pill status-select" data-id="<?= $lead->id ?>" style="width: auto;">
                                    <option value="NOVO" <?= $lead->status == 'NOVO' ? 'selected' : '' ?>>Novo</option>
                                    <option value="EM_ATENDIMENTO" <?= $lead->status == 'EM_ATENDIMENTO' ? 'selected' : '' ?>>Em Atendimento</option>
                                    <option value="CONCLUIDO" <?= $lead->status == 'CONCLUIDO' ? 'selected' : '' ?>>Concluído</option>
                                    <option value="PERDIDO" <?= $lead->status == 'PERDIDO' ? 'selected' : '' ?>>Perdido</option>
                                </select>
                            </td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-light rounded-circle p-2 view-lead" data-id="<?= $lead->id ?>" title="Explorar">
                                    <i class="fa-solid fa-eye text-primary"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="d-flex justify-content-center mt-4">
        <?php if ($pager) : ?>
        <?= $pager->links('default', 'bootstrap_full') ?>
        <?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<!-- Modal de Detalhes do Lead -->
<div class="modal fade" id="leadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 p-3">Detalhes do Interessado</h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 me-2" id="editLeadBtn">
                        <i class="fa-solid fa-pen-to-square me-1"></i> Editar
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-4 pt-2">
                <div id="leadLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                <div id="leadContent" style="display: none;">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="text-muted small text-uppercase fw-bold mb-3">Informações do Contato</h6>
                            <form id="leadEditForm">
                                <input type="hidden" name="id" id="editLeadId">
                                <div class="p-3 bg-light rounded-4">
                                    <div class="mb-3">
                                        <label class="mb-1 small text-muted">Nome</label>
                                        <p class="fw-bold mb-0 view-mode" id="leadName"></p>
                                        <input type="text" name="nome_visitante" id="inputLeadName" class="form-control form-control-sm edit-mode" style="display: none;">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="mb-1 small text-muted">E-mail</label>
                                        <p class="fw-bold mb-0 view-mode" id="leadEmail"></p>
                                        <input type="email" name="email_visitante" id="inputLeadEmail" class="form-control form-control-sm edit-mode" style="display: none;">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="mb-1 small text-muted">Telefone</label>
                                        <p class="fw-bold mb-0 view-mode" id="leadPhone"></p>
                                        <input type="text" name="telefone_visitante" id="inputLeadPhone" class="form-control form-control-sm edit-mode" style="display: none;">
                                    </div>

                                    <div class="mb-0">
                                        <label class="mb-1 small text-muted">Origem / Tipo</label>
                                        <p class="mb-0"><span id="leadOrigin" class="badge bg-secondary"></span> <span id="leadType" class="badge bg-info"></span></p>
                                    </div>
                                </div>
                                <div class="mt-3 edit-mode text-end" style="display: none;">
                                    <button type="button" class="btn btn-sm btn-light rounded-pill px-3" id="cancelEditBtn">Cancelar</button>
                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 ms-2">Salvar Alterações</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted small text-uppercase fw-bold mb-3">Histórico de Atividades</h6>
                            <div id="leadEvents" class="timeline-small" style="max-height: 300px; overflow-y: auto;">
                                <!-- Eventos aqui -->
                            </div>
                        </div>
                        <div class="col-12 mt-4 border-top pt-4" id="leadPropertyWrap" style="display: none;">
                            <h6 class="text-muted small text-uppercase fw-bold mb-3">Imóvel de Interesse</h6>
                            <div class="p-0 bg-light rounded-4 overflow-hidden border">
                                <div class="row g-0 align-items-center">
                                    <div class="col-3 col-sm-2">
                                        <img id="leadPropImg" src="" class="img-fluid w-100 object-fit-cover" style="height: 80px;" onerror="this.src='/assets/img/placeholder-house.png'">
                                    </div>
                                    <div class="col-9 col-sm-10 p-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="fw-bold mb-1 text-dark" id="leadPropTitle"></h6>
                                                <p class="text-muted small mb-0" id="leadPropLocation"></p>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold text-primary" id="leadPropPrice"></div>
                                                <a href="" id="leadPropLink" target="_blank" class="small text-decoration-none">Ver anúncio <i class="fa-solid fa-external-link small ms-1"></i></a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mt-4" id="leadMessageWrap" style="display: none;">
                            <h6 class="text-muted small text-uppercase fw-bold mb-2">Mensagem do Visitante</h6>
                            <div class="p-3 bg-light rounded-4 italic text-muted" id="leadMessage"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(document).ready(function() {
    console.log('Admin Leads JS Loaded');
    const adminLeadsBasePath = new URL('<?= site_url('admin/leads') ?>', window.location.origin).pathname;
    const csrfFieldName = '<?= csrf_token() ?>';
    let csrfHash = '<?= csrf_hash() ?>';

    function csrfPayload() {
        const payload = {};
        payload[csrfFieldName] = csrfHash;
        return payload;
    }

    function refreshCsrf(res) {
        if (res && res.csrf_hash) {
            csrfHash = res.csrf_hash;
        }
    }

    // Delegação de evento para garantir que funcione se a tabela for atualizada
    $(document).on('change', '.status-select', function() {
        const leadId = $(this).data('id');
        const newStatus = $(this).val();
        const select = $(this);
        
        select.prop('disabled', true);
        
        $.post(`${adminLeadsBasePath}/${leadId}/update-status`, {
            status: newStatus,
            ...csrfPayload()
        }, function(res) {
            refreshCsrf(res);
            if (res.success) {
                Toast.fire({ icon: 'success', title: res.message });
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        }).fail(function(xhr) {
            const res = xhr.responseJSON || {};
            refreshCsrf(res);
            Swal.fire('Erro', res.message || 'Falha ao atualizar status.', 'error');
        }).always(function() {
            select.prop('disabled', false);
        });
    });

    // Visualizar Lead
    $(document).on('click', '.view-lead', function() {
        const id = $(this).data('id');
        const modalEl = document.getElementById('leadModal');
        
        if (!modalEl) {
            console.error('Modal element #leadModal not found');
            return;
        }

        // Tenta usar a instância existente ou cria uma nova (Padrão Bootstrap 5)
        let modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        
        $('#leadLoading').show();
        $('#leadContent').hide();
        modal.show();

        $.get(`${adminLeadsBasePath}/${id}`, function(res) {
            refreshCsrf(res);
            if (res.success) {
                const lead = res.lead;
                const events = res.events;

                $('#editLeadId').val(lead.id);
                $('#leadName').text(lead.nome_visitante);
                $('#inputLeadName').val(lead.nome_visitante);

                $('#leadEmail').text(lead.email_visitante || 'Não informado');
                $('#inputLeadEmail').val(lead.email_visitante);

                $('#leadPhone').text(lead.telefone_visitante || 'Não informado');
                $('#inputLeadPhone').val(lead.telefone_visitante);

                $('#leadOrigin').text(lead.origem);
                $('#leadType').text(lead.tipo_lead);

                if (res.property) {
                    const prop = res.property;
                    $('#leadPropertyWrap').show();
                    $('#leadPropTitle').text(prop.titulo);
                    $('#leadPropLocation').text(`${prop.bairro}, ${prop.cidade}`);
                    
                    const price = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(prop.preco);
                    $('#leadPropPrice').text(price + (prop.tipo_negocio === 'ALUGUEL' ? '/mês' : ''));
                    
                    const baseUrl = '<?= base_url() ?>';
                    const imgUrl = prop.cover_image ? (prop.cover_image.startsWith('http') ? prop.cover_image : baseUrl + '/' + prop.cover_image) : baseUrl + '/assets/img/placeholder-house.png';
                    $('#leadPropImg').attr('src', imgUrl);
                    $('#leadPropLink').attr('href', `<?= site_url('imovel') ?>/${prop.id}`);
                } else {
                    $('#leadPropertyWrap').hide();
                }

                if (lead.mensagem) {
                    $('#leadMessageWrap').show();
                    $('#leadMessage').text(lead.mensagem);
                } else {
                    $('#leadMessageWrap').hide();
                }

                // Reseta modo edição
                $('.edit-mode').hide();
                $('.view-mode').show();
                $('#editLeadBtn').show();

                // Renderiza Eventos
                // ... (rest is same)
                const eventsContainer = $('#leadEvents');
                eventsContainer.empty();
                if (events.length === 0) {
                    eventsContainer.append('<p class="text-muted small">Nenhuma atividade registrada.</p>');
                } else {
                    events.forEach(ev => {
                        let dateStr = 'Data indisponível';
                        if (ev.created_at) {
                            try {
                                // Formato retornado pelo CI4 pode ser objeto com string date
                                const rawDate = typeof ev.created_at === 'object' ? ev.created_at.date : ev.created_at;
                                const date = new Date(rawDate.replace(/-/g, '/'));
                                if (!isNaN(date)) {
                                    dateStr = date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
                                }
                            } catch (e) { console.error('Error parsing date', e); }
                        }
                        
                        let eventLabel = ev.evento;
                        if (ev.evento === 'whatsapp_click') eventLabel = 'Clicou no WhatsApp';
                        if (ev.evento === 'status_changed') eventLabel = 'Status alterado';

                        eventsContainer.append(`
                            <div class="mb-3 border-start ps-3 py-1">
                                <div class="fw-bold small text-dark">${eventLabel}</div>
                                <div class="text-muted" style="font-size: 0.7rem;">${dateStr}</div>
                            </div>
                        `);
                    });
                }

                $('#leadLoading').hide();
                $('#leadContent').fadeIn();
            } else {
                Swal.fire('Erro', res.message, 'error');
                modal.hide();
            }
        }).fail(function() {
            Swal.fire('Erro', 'Falha ao carregar detalhes do lead.', 'error');
            modal.hide();
        });
    });
    // Clique no ID do Imóvel para Preview
    $(document).on('click', '.view-property-preview', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        $(`.view-lead[data-id="${id}"]`).click();
    });

    // Toggle Edição de Lead
    $('#editLeadBtn').on('click', function() {
        $('.view-mode').hide();
        $('.edit-mode').fadeIn();
        $(this).hide();
    });

    $('#cancelEditBtn').on('click', function() {
        $('.edit-mode').hide();
        $('.view-mode').fadeIn();
        $('#editLeadBtn').fadeIn();
    });

    // Exportar Leads
    $('.btn-export').on('click', function(e) {
        e.preventDefault();
        const format = $(this).data('format');
        const baseUrl = '<?= site_url('admin/export/leads') ?>';
        
        // Captura os parâmetros da URL atual (filtros)
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('format', format);
        
        window.location.href = baseUrl + '?' + urlParams.toString();
    });

    $('#leadEditForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#editLeadId').val();
        const formData = $(this).serializeArray();
        formData.push({ name: csrfFieldName, value: csrfHash });
        const btn = $(this).find('button[type="submit"]');

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `${adminLeadsBasePath}/${id}/update`,
            method: 'POST',
            data: $.param(formData),
            success: function(res) {
                refreshCsrf(res);
                if (res.success) {
                    Toast.fire({ icon: 'success', title: res.message });
                    // Atualiza os textos na visualização
                    $('#leadName').text($('#inputLeadName').val());
                    $('#leadEmail').text($('#inputLeadEmail').val() || 'Não informado');
                    $('#leadPhone').text($('#inputLeadPhone').val() || 'Não informado');
                    
                    // Volta para view mode
                    $('#cancelEditBtn').click();
                    
                    // Agenda reload suave da tabela (opcional, ou espera fechar modal)
                    // setTimeout(() => location.reload(), 2000);
                } else {
                    Swal.fire('Erro', res.message, 'error');
                }
            },
            error: function(xhr) {
                const res = xhr.responseJSON || {};
                refreshCsrf(res);
                Swal.fire('Erro', res.message || 'Falha na comunicação com o servidor.', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).text('Salvar Alterações');
            }
        });
    });
});
</script>
<?= $this->endSection() ?>

<?= $this->section('modals') ?>
<!-- Modal de Exportação -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="exportModalLabel"><i class="fa-solid fa-download me-2 text-primary"></i> Exportar Leads</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body pt-3">
                <p class="text-muted small mb-3">Escolha o formato do arquivo:</p>
                <div class="d-grid gap-2">
                    <a href="#" class="btn btn-outline-success btn-lg rounded-pill btn-export" data-format="csv">
                        <i class="fa-solid fa-file-csv me-2"></i> CSV
                    </a>
                    <a href="#" class="btn btn-outline-success btn-lg rounded-pill btn-export" data-format="xls">
                        <i class="fa-solid fa-file-excel me-2"></i> Excel (XLS)
                    </a>
                    <a href="#" class="btn btn-outline-danger btn-lg rounded-pill btn-export" data-format="pdf">
                        <i class="fa-solid fa-file-pdf me-2"></i> PDF
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
