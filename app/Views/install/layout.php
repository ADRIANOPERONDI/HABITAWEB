<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador - Habitaweb</title>
    
    <!-- Bootstrap 5.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .install-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
        }
        
        .install-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .install-header {
            background: linear-gradient(135deg, var(--primary), #4f46e5);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .install-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
        }
        
        .install-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
        }
        
        .progress-bar-custom {
            height: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 1rem;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: white;
            transition: width 0.3s ease;
        }
        
        .install-body {
            padding: 2rem;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        
        .step::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #e5e7eb;
            z-index: -1;
        }
        
        .step:first-child::before {
            display: none;
        }
        
        .step-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .step.active .step-circle {
            background: var(--primary);
            color: white;
        }
        
        .step.completed .step-circle {
            background: var(--success);
            color: white;
        }
        
        .step-label {
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .step.active .step-label {
            color: var(--primary);
            font-weight: 600;
        }
        
        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            background: #f9fafb;
        }
        
        .requirement.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .requirement.error {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
    
    <?= $this->renderSection('head') ?>
</head>
<body>
    <div class="install-container">
        <div class="install-card">
            <div class="install-header">
                <h1><i class="fas fa-home"></i> Habitaweb</h1>
                <p>Assistente de Instalação</p>
                <?php if (isset($currentStep)): ?>
                <div class="progress-bar-custom">
                    <div class="progress-bar-fill" style="width: <?= ($currentStep / 5) * 100 ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (isset($currentStep)): ?>
            <div class="install-body">
                <div class="step-indicator">
                    <div class="step <?= $currentStep >= 1 ? 'active' : '' ?> <?= $currentStep > 1 ? 'completed' : '' ?>">
                        <div class="step-circle">1</div>
                        <div class="step-label">Requisitos</div>
                    </div>
                    <div class="step <?= $currentStep >= 2 ? 'active' : '' ?> <?= $currentStep > 2 ? 'completed' : '' ?>">
                        <div class="step-circle">2</div>
                        <div class="step-label">Banco</div>
                    </div>
                    <div class="step <?= $currentStep >= 3 ? 'active' : '' ?> <?= $currentStep > 3 ? 'completed' : '' ?>">
                        <div class="step-circle">3</div>
                        <div class="step-label">Configs</div>
                    </div>
                    <div class="step <?= $currentStep >= 4 ? 'active' : '' ?> <?= $currentStep > 4 ? 'completed' : '' ?>">
                        <div class="step-circle">4</div>
                        <div class="step-label">Admin</div>
                    </div>
                    <div class="step <?= $currentStep >= 5 ? 'active' : '' ?> <?= $currentStep > 5 ? 'completed' : '' ?>">
                        <div class="step-circle">5</div>
                        <div class="step-label">Instalar</div>
                    </div>
                </div>
                
                <?= $this->renderSection('content') ?>
            </div>
            <?php else: ?>
                <?= $this->renderSection('content') ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <?= $this->renderSection('scripts') ?>
</body>
</html>
