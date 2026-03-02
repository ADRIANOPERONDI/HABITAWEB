/**
 * Liveness Check - Biometria Facial
 * Desenvolvido para Copia Zap
 * Dependências: Mediapipe Face Landmarker (via CDN)
 * 
 * IMPORTANTE: Cada etapa exige que o usuário MANTENHA a posição correta
 * por pelo menos 1.5 segundo antes de capturar a foto.
 * Isso garante que todas as 5 etapas sejam realmente realizadas.
 */

class LivenessCheck {
    constructor(videoElement, canvasElement, instructionsElement, submitButton) {
        this.video = videoElement;
        this.canvas = canvasElement;
        this.instructions = instructionsElement;
        this.submitBtn = submitButton;

        this.faceLandmarker = null;
        this.lastVideoTime = -1;

        this.status = 'IDLE'; // IDLE, STARTING, SCANNING, COMPLETED, FAILED
        this.currentStep = 0;
        this.steps = [
            { label: 'Olhe para FRENTE e fique parado', target: 'front', icon: '🙂' },
            { label: 'Vire a cabeça para a DIREITA', target: 'right', icon: '👉' },
            { label: 'Vire a cabeça para a ESQUERDA', target: 'left', icon: '👈' },
            { label: 'Olhe para CIMA', target: 'up', icon: '👆' },
            { label: 'Olhe para BAIXO', target: 'down', icon: '👇' }
        ];

        this.capturedFrames = [];
        this.stream = null;

        // Stabilization: User must hold the pose for this many consecutive frames
        this.HOLD_FRAMES_REQUIRED = 15; // ~0.5s at 30fps
        this.holdCounter = 0;

        // Cooldown between steps to prevent instant skipping
        this.stepCooldown = false;
        this.COOLDOWN_MS = 1200; // 1.2 seconds between captures

        // Progress bar element
        this.progressBar = null;
    }

    async init() {
        this.setStatus('STARTING');
        this.updateInstructions('⏳ Carregando módulos de segurança...');

        try {
            const vision = await FilesetResolver.forVisionTasks(
                "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.3/wasm"
            );

            this.faceLandmarker = await FaceLandmarker.createFromOptions(vision, {
                baseOptions: {
                    modelAssetPath: `https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task`,
                    delegate: "GPU"
                },
                outputFaceBlendshapes: true,
                runningMode: "VIDEO",
                numFaces: 1
            });

            this.updateInstructions('✅ Pronto! Posicione seu rosto no centro.');
            this.setStatus('IDLE');
        } catch (error) {
            console.error(error);
            this.updateInstructions('❌ Erro ao carregar biometria. Use um navegador moderno.');
            this.setStatus('FAILED');
        }
    }

    async startCamera() {
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: 'user'
                }
            });
            this.video.srcObject = this.stream;
            this.video.addEventListener("loadeddata", () => this.predictWebcam());
            this.setStatus('SCANNING');
            this.currentStep = 0;
            this.capturedFrames = [];
            this.holdCounter = 0;
            this.updateStepUI();
        } catch (err) {
            this.updateInstructions('🚫 Acesso à câmera negado. Permita o acesso e tente novamente.');
            this.setStatus('FAILED');
        }
    }

    updateStepUI() {
        if (this.currentStep >= this.steps.length) return;
        const step = this.steps[this.currentStep];
        this.updateInstructions(
            `<div style="font-size: 2rem; margin-bottom: 0.3rem;">${step.icon}</div>` +
            `<div>Etapa ${this.currentStep + 1}/5: <strong>${step.label}</strong></div>` +
            `<div class="liveness-progress-bar"><div class="liveness-progress-fill" id="holdProgress" style="width: 0%"></div></div>` +
            `<small style="opacity:0.7">Mantenha a posição até a barra completar</small>`
        );
        this.progressBar = document.getElementById('holdProgress');
    }

    async predictWebcam() {
        if (this.status !== 'SCANNING') return;

        let startTimeMs = performance.now();
        if (this.lastVideoTime !== this.video.currentTime) {
            this.lastVideoTime = this.video.currentTime;
            const results = this.faceLandmarker.detectForVideo(this.video, startTimeMs);

            if (results.faceLandmarks && results.faceLandmarks.length > 0) {
                this.processFaceMovement(results.faceLandmarks[0]);
            } else {
                this.holdCounter = 0;
                this.updateProgressBar(0);
                // Keep the step instruction visible, just add a warning
                const step = this.steps[this.currentStep];
                if (step) {
                    this.updateInstructions(
                        `<div style="font-size: 2rem; margin-bottom: 0.3rem;">⚠️</div>` +
                        `<div>Rosto não detectado. Centralize-se na câmera.</div>` +
                        `<div class="liveness-progress-bar"><div class="liveness-progress-fill" id="holdProgress" style="width: 0%"></div></div>` +
                        `<small style="opacity:0.7">Etapa ${this.currentStep + 1}/5</small>`
                    );
                    this.progressBar = document.getElementById('holdProgress');
                }
            }
        }

        window.requestAnimationFrame(() => this.predictWebcam());
    }

    processFaceMovement(landmarks) {
        if (this.stepCooldown) return;
        if (this.currentStep >= this.steps.length) return;

        // Calculate Yaw and Pitch from face landmarks
        const nose = landmarks[1];
        const leftFace = landmarks[234];
        const rightFace = landmarks[454];
        const topFace = landmarks[10];
        const bottomFace = landmarks[152];

        const yaw = (nose.x - leftFace.x) / (rightFace.x - leftFace.x);
        const pitch = (nose.y - topFace.y) / (bottomFace.y - topFace.y);

        const currentGoal = this.steps[this.currentStep];

        // Update face guide color
        const guide = document.getElementById('faceGuide');
        const isCentered = yaw > 0.35 && yaw < 0.65 && pitch > 0.35 && pitch < 0.65;
        if (guide) {
            guide.classList.toggle('face-guide-ok', isCentered);
        }

        let positionCorrect = false;

        switch (currentGoal.target) {
            case 'front':
                positionCorrect = yaw > 0.42 && yaw < 0.58 && pitch > 0.42 && pitch < 0.58;
                break;
            case 'right':
                // Camera is mirrored, so user turns their head to their right = yaw decreases
                positionCorrect = yaw < 0.30;
                break;
            case 'left':
                positionCorrect = yaw > 0.70;
                break;
            case 'up':
                positionCorrect = pitch < 0.35;
                break;
            case 'down':
                positionCorrect = pitch > 0.65;
                break;
        }

        if (positionCorrect) {
            this.holdCounter++;
            this.updateProgressBar((this.holdCounter / this.HOLD_FRAMES_REQUIRED) * 100);

            if (this.holdCounter >= this.HOLD_FRAMES_REQUIRED) {
                // SUCCESS: Capture this step
                this.captureFrame();
                this.holdCounter = 0;
                this.currentStep++;

                if (this.currentStep >= this.steps.length) {
                    this.completeVerification();
                } else {
                    // Start cooldown to prevent instant next-step detection
                    this.stepCooldown = true;
                    this.updateInstructions(
                        `<div style="font-size: 2rem; margin-bottom: 0.3rem;">✅</div>` +
                        `<div><strong>Etapa ${this.currentStep}/5 concluída!</strong></div>` +
                        `<div class="liveness-progress-bar"><div class="liveness-progress-fill" style="width: 100%; background: #10b981;"></div></div>` +
                        `<small style="opacity:0.7">Preparando próxima etapa...</small>`
                    );

                    setTimeout(() => {
                        this.stepCooldown = false;
                        this.holdCounter = 0;
                        this.updateStepUI();
                    }, this.COOLDOWN_MS);
                }
            }
        } else {
            // Reset hold if user moves away from target
            if (this.holdCounter > 0) {
                this.holdCounter = Math.max(0, this.holdCounter - 3); // Gradual decrease
                this.updateProgressBar((this.holdCounter / this.HOLD_FRAMES_REQUIRED) * 100);
            }
            // Keep showing the current step instruction
            if (!this.progressBar) {
                this.updateStepUI();
            }
        }
    }

    updateProgressBar(percentage) {
        if (this.progressBar) {
            this.progressBar.style.width = Math.min(100, percentage) + '%';
            if (percentage >= 100) {
                this.progressBar.style.background = '#10b981';
            } else if (percentage > 50) {
                this.progressBar.style.background = '#f59e0b';
            } else {
                this.progressBar.style.background = 'var(--primary-color, #3b82f6)';
            }
        }
    }

    captureFrame() {
        const ctx = this.canvas.getContext('2d');
        this.canvas.width = this.video.videoWidth;
        this.canvas.height = this.video.videoHeight;

        // Mirror (standard for selfie)
        ctx.save();
        ctx.scale(-1, 1);
        ctx.drawImage(this.video, -this.canvas.width, 0, this.canvas.width, this.canvas.height);
        ctx.restore();

        // Use toDataURL (SYNCHRONOUS) to guarantee the frame is captured immediately
        const dataUrl = this.canvas.toDataURL('image/jpeg', 0.85);
        const byteString = atob(dataUrl.split(',')[1]);
        const mimeString = dataUrl.split(',')[0].split(':')[1].split(';')[0];
        const ab = new ArrayBuffer(byteString.length);
        const ia = new Uint8Array(ab);
        for (let i = 0; i < byteString.length; i++) {
            ia[i] = byteString.charCodeAt(i);
        }
        const blob = new Blob([ab], { type: mimeString });
        this.capturedFrames.push(blob);
        console.log(`Liveness: Frame ${this.capturedFrames.length}/5 captured (${blob.size} bytes)`);

        // Visual flash feedback
        this.video.style.opacity = '0.3';
        setTimeout(() => this.video.style.opacity = '1', 200);
    }

    completeVerification() {
        this.setStatus('COMPLETED');

        // Stop camera
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
        }

        // Hide camera container (no more black screen)
        const container = document.getElementById('livenessContainer');
        if (container) container.style.display = 'none';

        // Show success message with previews
        const previewArea = document.getElementById('livenessPreview');
        if (previewArea) {
            const labels = ['Frente', 'Direita', 'Esquerda', 'Cima', 'Baixo'];
            previewArea.innerHTML = '<div class="w-100 mb-2"><strong><i class="fas fa-check-circle text-success me-1"></i> Biometria Concluída!</strong> ' +
                this.capturedFrames.length + ' fotos capturadas:</div>';
            previewArea.style.display = 'flex';
            previewArea.style.flexWrap = 'wrap';
            previewArea.style.gap = '8px';

            this.capturedFrames.forEach((blob, index) => {
                const url = URL.createObjectURL(blob);
                const wrapper = document.createElement('div');
                wrapper.style.textAlign = 'center';

                const img = document.createElement('img');
                img.src = url;
                img.className = 'img-thumbnail';
                img.style.width = '80px';
                img.style.height = '80px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '10px';

                const label = document.createElement('div');
                label.className = 'small text-muted mt-1';
                label.textContent = labels[index] || `Foto ${index + 1}`;

                wrapper.appendChild(img);
                wrapper.appendChild(label);
                previewArea.appendChild(wrapper);
            });
        }

        // Add hidden file inputs to the form
        const form = document.querySelector('form[action*="admin/profile"]');

        this.capturedFrames.forEach((blob, index) => {
            const file = new File([blob], `liveness_${index}.jpg`, { type: "image/jpeg" });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);

            const input = document.createElement('input');
            input.type = 'file';
            input.name = 'liveness_frames[]';
            input.style.display = 'none';
            input.files = dataTransfer.files;
            form.appendChild(input);
        });

        console.log(`Liveness: Verification complete! ${this.capturedFrames.length} frames captured.`);

        // Update submit button state based on form completeness
        this.checkFormCompleteness();
    }

    checkFormCompleteness() {
        const idFront = document.getElementById('idFrontInput');
        const idBack = document.getElementById('idBackInput');
        const selfie = document.getElementById('selfieInput');
        const isLivenessDone = this.status === 'COMPLETED';

        // Check ONLY actual file inputs — new files selected by the user in THIS session
        // Also check if server already has images (data attribute set by PHP)
        const hasFront = (idFront && idFront.files.length > 0) ||
            idFront?.dataset.hasExisting === 'true';
        const hasBack = (idBack && idBack.files.length > 0) ||
            idBack?.dataset.hasExisting === 'true';
        const hasSelfie = (selfie && selfie.files.length > 0) ||
            selfie?.dataset.hasExisting === 'true';

        // Count how many items are done
        const doneCount = [hasFront, hasBack, hasSelfie, isLivenessDone].filter(Boolean).length;
        const totalRequired = 4; // Front + Back + Selfie + Liveness

        // Always show helpful status on the button
        if (isLivenessDone && doneCount < totalRequired) {
            const missing = [];
            if (!hasFront) missing.push('RG Frente');
            if (!hasBack) missing.push('RG Verso');
            if (!hasSelfie) missing.push('Selfie');

            this.submitBtn.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i> Falta: ${missing.join(', ')}`;
            this.submitBtn.classList.remove('btn-primary', 'btn-success', 'animate-pulse');
            this.submitBtn.classList.add('btn-warning', 'text-dark');
        } else if (!isLivenessDone) {
            // Liveness not done yet — keep original button
            // Don't change anything
        }

        if (hasFront && hasBack && hasSelfie && isLivenessDone) {
            this.submitBtn.disabled = false;
            this.submitBtn.classList.remove('btn-primary', 'btn-info', 'btn-warning');
            this.submitBtn.classList.add('btn-success', 'animate-pulse');
            this.submitBtn.innerHTML = '<i class="fas fa-save me-2"></i> Tudo Pronto! Clique para Enviar';
        }
    }

    setStatus(status) {
        this.status = status;
        console.log("Liveness Status:", status);
    }

    updateInstructions(html) {
        this.instructions.innerHTML = html;
    }
}

// Export global
window.LivenessCheck = LivenessCheck;
