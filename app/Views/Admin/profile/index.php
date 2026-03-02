<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Meu Perfil<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Configurações da Conta<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .profile-card { border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    .logo-preview-wrapper { width: 120px; height: 120px; border-radius: 20px; overflow: visible; background: #fff; border: 2px dashed #e2e8f0; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; position: relative; transition: all 0.3s ease; }
    .logo-preview-wrapper:hover { border-color: var(--primary-color); background: rgba(var(--primary-rgb), 0.02); }
    .logo-preview-wrapper img { max-width: 100%; max-height: 100%; object-fit: cover; border-radius: 18px; }
    .upload-btn-chip { position: absolute; bottom: -5px; right: -5px; background: var(--primary-color); color: #fff; width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 3px solid #fff; box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3); transition: all 0.2s ease; z-index: 5; }
    .upload-btn-chip:hover { transform: scale(1.1); background: var(--secondary-color); }
    .verification-upload-box { border: 2px dashed #e2e8f0; border-radius: 15px; height: 150px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; transition: all 0.3s ease; background: #f8fafc; overflow: hidden; position: relative; }
    .verification-upload-box:hover { border-color: var(--primary-color); background: rgba(var(--primary-rgb), 0.05); }
    .verification-upload-box img { width: 100%; height: 100%; object-fit: cover; }
    
    /* Biometria Styles */
    #livenessContainer { position: relative; border-radius: 20px; overflow: hidden; background: #000; display: none; margin-bottom: 1.5rem; }
    #livenessVideo { width: 100%; height: auto; transform: scaleX(-1); }
    #livenessCanvas { display: none; }
    #livenessInstructions { 
        position: absolute; bottom: 0; left: 0; right: 0; 
        background: rgba(0,0,0,0.7); color: #fff; padding: 1rem; 
        text-align: center; font-weight: bold; font-size: 1.1rem;
        backdrop-filter: blur(5px);
    }
    .biometry-indicator { 
        position: absolute; top: 1rem; right: 1rem; 
        background: var(--primary-color); color: #fff; 
        padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.8rem;
        z-index: 10;
    }
    
    #faceGuide {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 240px; height: 320px;
        border: 4px solid rgba(255,255,255,0.7);
        border-radius: 50% / 40%;
        box-shadow: 0 0 0 2000px rgba(0,0,0,0.6);
        pointer-events: none;
        z-index: 5;
        display: flex;
        align-items: center; justify-content: center;
    }
    #faceGuide::before {
        content: "\f21b";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        color: rgba(255,255,255,0.3);
        font-size: 5rem;
    }
    .face-guide-ok { border-color: #10b981 !important; border-style: solid !important; }
    .face-guide-ok::before { color: rgba(16, 185, 129, 0.5) !important; }
    .animate-pulse { animation: pulse 1.5s infinite; }
    @keyframes pulse {
        0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
        100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .liveness-progress-bar {
        width: 80%; margin: 0.5rem auto; height: 8px;
        background: rgba(255,255,255,0.2); border-radius: 10px; overflow: hidden;
    }
    .liveness-progress-fill {
        height: 100%; border-radius: 10px;
        background: var(--primary-color, #3b82f6);
        transition: width 0.15s ease;
    }

    /* Camera Capture Modal */
    #cameraModal {
        display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.9); z-index: 9999;
        flex-direction: column; align-items: center; justify-content: center;
    }
    #cameraModal.active { display: flex; }
    #cameraModal video {
        max-width: 90vw; max-height: 60vh; border-radius: 15px;
        transform: scaleX(-1);
    }
    #cameraModal canvas { display: none; }
    #cameraModal .cam-controls {
        display: flex; gap: 1rem; margin-top: 1.5rem;
    }
    #cameraModal .cam-btn {
        width: 70px; height: 70px; border-radius: 50%; border: 4px solid #fff;
        background: transparent; color: #fff; font-size: 1.5rem;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all 0.2s;
    }
    #cameraModal .cam-btn:hover { background: rgba(255,255,255,0.2); }
    #cameraModal .cam-btn.capture { background: #ef4444; border-color: #ef4444; }
    #cameraModal .cam-btn.capture:hover { background: #dc2626; }
    #cameraModal .cam-label {
        color: #fff; text-align: center; font-size: 1.1rem; font-weight: bold;
        margin-bottom: 1rem;
    }
    .verification-upload-box .upload-badge {
        position: absolute; bottom: 5px; right: 5px;
        background: var(--primary-color); color: #fff;
        width: 28px; height: 28px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.7rem;
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-lg-8">
        <div class="card profile-card animate-fade-in">
            <div class="card-body p-4 p-md-5">
                <form action="<?= site_url('admin/profile') ?>" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    
                    <div class="d-flex align-items-center gap-4 mb-5 pb-4 border-bottom">
                        <div class="logo-preview-wrapper">
                            <?php if ($account->logo): ?>
                                <img src="<?= base_url($account->logo) ?>" alt="Logo da Conta" id="logoPreview">
                            <?php else: ?>
                                <i class="fa-solid fa-building fa-2x text-light" id="logoPlaceholder"></i>
                                <img src="" alt="Preview" id="logoPreview" style="display: none;">
                            <?php endif; ?>
                            <label for="logoInput" class="upload-btn-chip">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" name="logo" id="logoInput" class="d-none" accept="image/*" onchange="previewImage(this)">
                        </div>
                        <div>
                            <h4 class="fw-bold mb-1"><?= esc($account->nome) ?></h4>
                            <p class="text-muted mb-0 small text-uppercase fw-bold letter-spacing-1"><?= esc($account->tipo_conta) ?></p>
                            <div class="d-flex gap-2 mt-2">
                                <span class="badge bg-success-soft text-success rounded-pill px-3 py-2">Conta Ativa</span>
                                
                                <?php if ($account->verification_status === 'APPROVED'): ?>
                                    <span class="badge bg-primary rounded-pill px-3 py-2"><i class="fas fa-check-circle"></i> Verificada</span>
                                <?php elseif ($account->verification_status === 'PENDING'): ?>
                                    <span class="badge bg-warning text-dark rounded-pill px-3 py-2"><i class="fas fa-clock"></i> Verificação Pendente</span>
                                <?php elseif ($account->verification_status === 'REJECTED'): ?>
                                    <span class="badge bg-danger rounded-pill px-3 py-2"><i class="fas fa-times-circle"></i> Verificação Rejeitada</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-soft text-muted rounded-pill px-3 py-2"><i class="fas fa-id-card"></i> Não Verificada</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Nome do Corretor (Exibido nos Imóveis)</label>
                            <input type="text" name="user_nome" class="form-control input-premium" value="<?= esc($user->nome ?? $user->username) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Nome da Imobiliária / Conta</label>
                            <input type="text" name="nome" class="form-control input-premium" value="<?= esc($account->nome) ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold small text-muted">CPF / CNPJ (Obrigatório para Pagamentos)</label>
                            <input type="text" name="documento" class="form-control input-premium" value="<?= esc($account->documento) ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">E-mail de Contato</label>
                            <input type="email" name="email" class="form-control input-premium" value="<?= esc($account->email) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">CRECI (Se houver)</label>
                            <input type="text" name="creci" class="form-control input-premium" value="<?= esc($account->creci) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Telefone</label>
                            <input type="text" name="telefone" class="form-control input-premium" value="<?= esc($account->telefone) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">WhatsApp</label>
                            <input type="text" name="whatsapp" class="form-control input-premium" value="<?= esc($account->whatsapp) ?>">
                        </div>
                    </div>

                    <div class="mt-5 pt-4 border-top">
                        <h5 class="fw-bold mb-4"><i class="fas fa-shield-alt text-primary me-2"></i> Verificação de Identidade (Anti-Fraude)</h5>
                        
                            <?php if ($account->verification_status === 'APPROVED'): ?>
                                <!-- APROVADA: Não mostra formulário, só badge de sucesso -->
                                <div class="alert alert-success border-0 rounded-4 mb-4">
                                    <h6 class="fw-bold mb-1"><i class="fas fa-check-circle me-2"></i> Identidade Verificada</h6>
                                    <p class="mb-0 small">Sua conta foi verificada com sucesso. Você pode postar e editar imóveis normalmente.</p>
                                </div>

                            <?php elseif ($account->verification_status === 'PENDING'): ?>
                                <!-- PENDENTE: Mostra mensagem de espera, SEM formulário -->
                                <div class="alert alert-info border-0 rounded-4 mb-4">
                                    <h6 class="fw-bold mb-1"><i class="fas fa-hourglass-half me-2"></i> Verificação em Análise</h6>
                                    <p class="mb-0 small">Seus documentos e biometria foram enviados com sucesso e estão sendo analisados. Avisaremos assim que a verificação for concluída.</p>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4 text-center">
                                        <label class="form-label fw-bold small">RG ou CNH (Frente)</label>
                                        <div class="verification-upload-box" style="cursor: default; pointer-events: none;">
                                            <?php if($account->id_front): ?>
                                                <img src="<?= base_url($account->id_front) ?>" class="img-fluid rounded">
                                            <?php else: ?>
                                                <i class="fas fa-check text-success fa-2x"></i>
                                                <span class="d-block small mt-2 text-success">Enviado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <label class="form-label fw-bold small">RG ou CNH (Verso)</label>
                                        <div class="verification-upload-box" style="cursor: default; pointer-events: none;">
                                            <?php if($account->id_back): ?>
                                                <img src="<?= base_url($account->id_back) ?>" class="img-fluid rounded">
                                            <?php else: ?>
                                                <i class="fas fa-check text-success fa-2x"></i>
                                                <span class="d-block small mt-2 text-success">Enviado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <label class="form-label fw-bold small">Selfie com Documento</label>
                                        <div class="verification-upload-box" style="cursor: default; pointer-events: none;">
                                            <?php if($account->selfie): ?>
                                                <img src="<?= base_url($account->selfie) ?>" class="img-fluid rounded">
                                            <?php else: ?>
                                                <i class="fas fa-check text-success fa-2x"></i>
                                                <span class="d-block small mt-2 text-success">Enviado</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="alert alert-light border rounded-4 text-center py-3">
                                    <i class="fas fa-fingerprint fa-2x text-primary mb-2"></i>
                                    <p class="mb-0 small fw-bold text-muted">Biometria Facial <span class="text-success">✓ Concluída</span></p>
                                </div>

                            <?php else: ?>
                                <!-- NONE ou REJECTED: Mostra formulário completo -->
                                <?php if ($account->verification_status === 'REJECTED'): ?>
                                    <div class="alert alert-danger border-0 rounded-4 mb-4">
                                        <h6 class="fw-bold mb-1"><i class="fas fa-exclamation-triangle me-2"></i> Verificação Rejeitada</h6>
                                        <p class="mb-1 small"><?= esc($account->verification_notes ?: 'Seus documentos foram recusados.') ?></p>
                                        <p class="mb-0 small fw-bold">Por favor, envie <u>todos os documentos novamente</u> e refaça a biometria facial.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning border-0 rounded-4 mb-4">
                                        <i class="fas fa-info-circle me-2"></i> <strong>Atenção:</strong> De acordo com as novas regras de segurança, você precisa verificar sua identidade para postar ou editar imóveis.
                                    </div>
                                <?php endif; ?>

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">RG ou CNH (Frente)</label>
                                    <div class="verification-upload-box" onclick="document.getElementById('idFrontInput').click()" id="boxFront">
                                        <?php if($account->id_front && $account->verification_status !== 'REJECTED'): ?>
                                            <img src="<?= base_url($account->id_front) ?>" class="img-fluid rounded">
                                        <?php else: ?>
                                            <i class="fas fa-id-card fa-2x opacity-25"></i>
                                            <span class="d-block small mt-2">Clique para Upload</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="file" name="id_front" id="idFrontInput" class="d-none" accept="image/*" data-has-existing="<?= (!empty($account->id_front) && $account->verification_status !== 'REJECTED') ? 'true' : 'false' ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">RG ou CNH (Verso)</label>
                                    <div class="verification-upload-box" onclick="document.getElementById('idBackInput').click()" id="boxBack">
                                        <?php if($account->id_back && $account->verification_status !== 'REJECTED'): ?>
                                            <img src="<?= base_url($account->id_back) ?>" class="img-fluid rounded">
                                        <?php else: ?>
                                            <i class="fas fa-id-card fa-2x opacity-25"></i>
                                            <span class="d-block small mt-2">Clique para Upload</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="file" name="id_back" id="idBackInput" class="d-none" accept="image/*" data-has-existing="<?= (!empty($account->id_back) && $account->verification_status !== 'REJECTED') ? 'true' : 'false' ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Selfie com Documento</label>
                                    <div class="verification-upload-box" onclick="openCamera('selfieInput', 'Selfie com Documento', 'user')" id="boxSelfie">
                                        <?php if($account->selfie && $account->verification_status !== 'REJECTED'): ?>
                                            <img src="<?= base_url($account->selfie) ?>" class="img-fluid rounded">
                                        <?php else: ?>
                                            <i class="fas fa-camera fa-2x opacity-25"></i>
                                            <span class="d-block small mt-2">Toque para Tirar Foto</span>
                                        <?php endif; ?>
                                        <div class="upload-badge"><i class="fas fa-camera"></i></div>
                                    </div>
                                    <input type="file" name="selfie" id="selfieInput" class="d-none" accept="image/*" data-has-existing="<?= (!empty($account->selfie) && $account->verification_status !== 'REJECTED') ? 'true' : 'false' ?>">
                                </div>
                            </div>

                            <!-- CAMERA CAPTURE MODAL -->
                            <div id="cameraModal">
                                <div class="cam-label" id="cameraLabel">Tirar Foto</div>
                                <video id="camVideo" autoplay playsinline></video>
                                <canvas id="camCanvas"></canvas>
                                <div class="cam-controls">
                                    <button type="button" class="cam-btn" onclick="closeCameraModal()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button type="button" class="cam-btn capture" onclick="takePicture()">
                                        <i class="fas fa-camera"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- BIOMETRY INTERFACE -->
                            <div class="mt-4">
                                <button type="button" id="startLivenessBtn" class="btn btn-outline-primary rounded-pill w-100 py-3 fw-bold mb-3">
                                    <i class="fas fa-video me-2"></i> Iniciar Validação Facial em Tempo Real
                                </button>

                                <div id="livenessContainer">
                                    <video id="livenessVideo" autoplay playsinline></video>
                                    <canvas id="livenessCanvas"></canvas>
                                    <div id="faceGuide"></div>
                                    <div class="biometry-indicator"><i class="fas fa-fingerprint me-1"></i> Biometria Ativa</div>
                                    <div id="livenessInstructions">Posicione seu rosto no centro da câmera</div>
                                </div>

                                <div id="livenessPreview" class="mt-3 p-3 bg-light rounded-4" style="display: none; border: 1px solid #e2e8f0; align-items: center;">
                                    <!-- Previews will be injected here -->
                                </div>

                                <p class="text-muted extra-small text-center"><i class="fas fa-shield-alt me-1"></i> Usamos Inteligência Artificial segura para validar sua identidade.</p>
                            </div>
                            <?php endif; ?>
                    </div>

                    <div class="mt-5 pt-4 border-top">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold">
                            <i class="fa-solid fa-save me-2"></i> Salvar e Enviar para Verificação
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card profile-card border-0 bg-dark text-white p-4 h-100">
            <h5 class="fw-bold mb-4">Dicas de Branding</h5>
            <div class="d-flex flex-column gap-3">
                <div class="d-flex gap-3">
                    <div class="text-primary fs-4"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <h6 class="mb-1 small fw-bold">Logo Transparente</h6>
                        <p class="mb-0 xsmall opacity-75">Use arquivos PNG com fundo transparente para um visual mais profissional.</p>
                    </div>
                </div>
                <div class="d-flex gap-3">
                    <div class="text-primary fs-4"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <h6 class="mb-1 small fw-bold">Tamanho Ideal</h6>
                        <p class="mb-0 xsmall opacity-75">Recomendamos 400x400px para que sua marca fique nítida em todos os dispositivos.</p>
                    </div>
                </div>
            </div>
            
            <div class="mt-auto p-3 rounded-4" style="background: rgba(255,255,255,0.1)">
                <p class="mb-0 xsmall fw-bold"><i class="fa-solid fa-info-circle me-1"></i> Sua logo será exibida em:</p>
                <ul class="xsmall opacity-75 mt-2 mb-0 ps-3">
                    <li>Página de detalhes dos seus imóveis</li>
                    <li>Perfil de busca do portal</li>
                    <li>Cards de listagem</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
<!-- Mediapipe & Liveness -->
<script type="module">
    import { FaceLandmarker, FilesetResolver } from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.3";
    window.FaceLandmarker = FaceLandmarker;
    window.FilesetResolver = FilesetResolver;
</script>
<script src="<?= base_url('js/liveness.js') ?>" defer></script>
<script>

function previewImage(input) {
    // ... (rest of existing preview script)
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#logoPreview').attr('src', e.target.result).show();
            $('#logoPlaceholder').hide();
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function previewDoc(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $(input).prev('.verification-upload-box').html(`<img src="${e.target.result}" class="img-fluid rounded">`);
        }
        reader.readAsDataURL(input.files[0]);
    }
}

$('#idFrontInput, #idBackInput, #selfieInput').on('change', function() {
    previewDoc(this);
});

// ====== CAMERA CAPTURE MODAL ======
let camStream = null;
let camTargetInputId = null;

function openCamera(targetInputId, label, facingMode) {
    camTargetInputId = targetInputId;
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('camVideo');
    const labelEl = document.getElementById('cameraLabel');
    
    labelEl.textContent = '📷 ' + label;
    modal.classList.add('active');

    navigator.mediaDevices.getUserMedia({
        video: {
            width: { ideal: 1280 },
            height: { ideal: 720 },
            facingMode: facingMode || 'environment'
        }
    }).then(stream => {
        camStream = stream;
        video.srcObject = stream;
    }).catch(err => {
        console.error('Camera error:', err);
        alert('Não foi possível acessar a câmera. Verifique as permissões do navegador.');
        closeCameraModal();
    });
}

function takePicture() {
    const video = document.getElementById('camVideo');
    const canvas = document.getElementById('camCanvas');
    const ctx = canvas.getContext('2d');

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    
    // Mirror for user-facing camera
    ctx.save();
    ctx.scale(-1, 1);
    ctx.drawImage(video, -canvas.width, 0, canvas.width, canvas.height);
    ctx.restore();

    // Convert to blob and assign to the hidden file input
    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
    const byteString = atob(dataUrl.split(',')[1]);
    const ab = new ArrayBuffer(byteString.length);
    const ia = new Uint8Array(ab);
    for (let i = 0; i < byteString.length; i++) {
        ia[i] = byteString.charCodeAt(i);
    }
    const blob = new Blob([ab], { type: 'image/jpeg' });
    const file = new File([blob], 'camera_capture.jpg', { type: 'image/jpeg' });
    
    // Assign to hidden input
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    const input = document.getElementById(camTargetInputId);
    input.files = dataTransfer.files;
    
    // Trigger change event for listeners
    input.dispatchEvent(new Event('change', { bubbles: true }));

    // Update the preview box with the captured image
    const box = input.closest('.col-md-4').querySelector('.verification-upload-box');
    if (box) {
        box.innerHTML = `<img src="${dataUrl}" class="img-fluid rounded" style="width:100%;height:100%;object-fit:cover;">` +
                         '<div class="upload-badge"><i class="fas fa-check"></i></div>';
    }

    closeCameraModal();
}

function closeCameraModal() {
    const modal = document.getElementById('cameraModal');
    modal.classList.remove('active');
    
    if (camStream) {
        camStream.getTracks().forEach(track => track.stop());
        camStream = null;
    }
}

$(document).ready(function() {
    // Máscara dinâmica para CPF/CNPJ
    const docMaskBehavior = function (val) {
        return val.replace(/\D/g, '').length <= 11 ? '000.000.000-009' : '00.000.000/0000-00';
    };

    const docOptions = {
        onKeyPress: function(val, e, field, options) {
            field.mask(docMaskBehavior.apply({}, arguments), options);
        }
    };

    $('input[name="documento"]').mask(docMaskBehavior, docOptions);

    // Máscara para Telefone (com 9º dígito opcional)
    const phoneMaskBehavior = function (val) {
        return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
    };

    const phoneOptions = {
        onKeyPress: function(val, e, field, options) {
            field.mask(phoneMaskBehavior.apply({}, arguments), options);
        }
    };

    $('input[name="telefone"], input[name="whatsapp"]').mask(phoneMaskBehavior, phoneOptions);

    // Liveness Init
    const startBtn = document.getElementById('startLivenessBtn');
    const container = document.getElementById('livenessContainer');
    const video = document.getElementById('livenessVideo');
    const canvas = document.getElementById('livenessCanvas');
    const instructions = document.getElementById('livenessInstructions');
    const submitBtn = document.querySelector('button[type="submit"]');

    let liveness = null;

    if (startBtn) {
        startBtn.addEventListener('click', async () => {
            if (!window.FaceLandmarker || !window.LivenessCheck) {
                alert('Os módulos de segurança ainda estão carregando. Por favor, aguarde alguns segundos.');
                return;
            }

            if (!liveness) {
                liveness = new LivenessCheck(video, canvas, instructions, submitBtn);
                await liveness.init();
            }

            startBtn.style.display = 'none';
            container.style.display = 'block';
            liveness.startCamera();
        });
    }

    // Listeners para os outros documentos para atualizar o botão de envio
    const idFront = document.getElementById('idFrontInput');
    const idBack = document.getElementById('idBackInput');
    const selfieInput = document.getElementById('selfieInput');
    
    [idFront, idBack, selfieInput].forEach(input => {
        if (input) {
            input.addEventListener('change', () => {
                if (liveness) {
                    liveness.checkFormCompleteness();
                }
            });
        }
    });
});
</script>
<?= $this->endSection() ?>
