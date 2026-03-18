<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { 
            background: <?= $percentage >= 100 ? 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)' : 'linear-gradient(135deg, #6366f1 0%, #a855f7 100%)' ?>; 
            color: white; 
            padding: 30px; 
            text-align: center; 
            border-radius: 8px 8px 0 0; 
        }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .progress-container { background: #e5e7eb; border-radius: 10px; height: 30px; margin: 20px 0; overflow: hidden; }
        .progress-bar { 
            height: 100%; 
            background: <?= $percentage >= 100 ? '#ef4444' : ($percentage >= 95 ? '#f59e0b' : '#6366f1') ?>; 
            width: <?= min($percentage, 100) ?>%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            font-weight: bold;
            transition: width 0.3s ease;
        }
        .usage-box { background: white; padding: 25px; border-radius: 8px; margin:20px 0; border-left: 4px solid <?= $percentage >= 100 ? '#ef4444' : '#6366f1' ?>; text-align: center; }
        .usage-number { font-size: 48px; font-weight: bold; color: <?= $percentage >= 100 ? '#ef4444' : '#6366f1' ?>; margin: 10px 0; }
        .btn { display: inline-block; padding: 15px 30px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; font-weight: bold; }
        .btn:hover { background: #4f46e5; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?= $percentage >= 100 ? '🚫 Limite de Imóveis Atingido!' : '⚠️ Atenção! Limite Próximo' ?></h1>
        </div>
        <div class="content">
            <p>Olá, <strong><?= esc($account->nome ?? 'Usuário') ?></strong>!</p>
            
            <?php if ($percentage >= 100): ?>
                <div style="background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <strong>⚠️ Você atingiu o limite máximo de imóveis ativos do seu plano!</strong>
                    <p style="margin: 10px 0 0 0;">Para publicar novos anúncios, você precisa fazer upgrade do plano ou desativar alguns imóveis existentes.</p>
                </div>
            <?php else: ?>
                <p style="font-size: 16px;">Você está usando <strong><?= esc($percentage) ?>%</strong> do seu limite de imóveis ativos.</p>
            <?php endif; ?>
            
            <div class="usage-box">
                <div>Uso Atual</div>
                <div class="usage-number"><?= esc($currentCount) ?> / <?= esc($limitCount) ?></div>
                <div style="color: #666; font-size: 14px;">imóveis ativos</div>
            </div>
            
            <div class="progress-container">
                <div class="progress-bar">
                    <?= esc($percentage) ?>%
                </div>
            </div>
            
            <?php if ($percentage >= 100): ?>
                <h3>🚫 Você não pode publicar novos imóveis</h3>
                <p>Para continuar, você tem duas opções:</p>
                <ul>
                    <li><strong>Fazer upgrade do plano</strong> e aumentar o limite</li>
                    <li><strong>Desativar alguns imóveis</strong> que não estão gerando leads</li>
                </ul>
            <?php elseif ($percentage >= 95): ?>
                <h3>⏰ Quase no limite!</h3>
                <p>Você está muito próximo de atingir o limite. Considere fazer upgrade antes de ficar sem vagas.</p>
            <?php else: ?>
                <h3>📊 Acompanhamento de Uso</h3>
                <p>Este é apenas um aviso para você acompanhar o uso do seu plano. Planeje com antecedência!</p>
            <?php endif; ?>
            
            <h3>✨ Vantagens de Fazer Upgrade</h3>
            <ul>
                <li>Mais imóveis ativos simultaneamente</li>
                <li>Maior destaque nas buscas</li>
                <li>Suporte prioritário</li>
                <li>Recursos exclusivos</li>
            </ul>
            
            <div style="text-align: center;">
                <a href="<?= site_url('admin/subscription') ?>" class="btn">
                    <?= $percentage >= 100 ? 'Fazer Upgrade Agora' : 'Ver Planos Disponíveis' ?>
                </a>
            </div>
            
            <p style="margin-top: 30px; font-size: 14px; color: #666; text-align: center;">
                <strong>Dica:</strong> Imóveis inativos ou pausados não contam no limite!
            </p>
            
            <div class="footer">
                <p>Este é um email automático do sistema de notificações.</p>
                <p>&copy; <?= date('Y') ?> Habitaweb - Todos os direitos reservados</p>
            </div>
        </div>
    </div>
</body>
</html>
