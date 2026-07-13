<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .alert { background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .alert h2 { margin: 0 0 10px 0; font-size: 32px; }
        .btn { display: inline-block; padding: 15px 30px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; font-weight: bold; }
        .btn:hover { background: #4f46e5; }
        .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f59e0b; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⏰ Sua Assinatura Está Expirando!</h1>
        </div>
        <div class="content">
            <p>Olá, <strong><?= esc($user->username ?? 'Usuário') ?></strong>!</p>
            
            <div class="alert">
                <h2><?= $daysRemaining ?></h2>
                <p style="margin: 0; font-size: 18px;"><?= $daysRemaining === 1 ? 'dia restante' : 'dias restantes' ?></p>
            </div>
            
            <p style="font-size: 16px; font-weight: bold; text-align: center;">
                Sua assinatura vence em <span style="color: #f59e0b;"><?= date('d/m/Y', strtotime($subscription->data_fim)) ?></span>
            </p>
            
            <div class="info-box">
                <h3>📋 Detalhes da Assinatura</h3>
                <p><strong>Plano Atual:</strong> <?= esc($subscription->plano_nome ?? 'Não informado') ?></p>
                <p><strong>Status:</strong> <span style="color: #10b981;">Ativo</span></p>
                <p><strong>Data de Vencimento:</strong> <?= date('d/m/Y', strtotime($subscription->data_fim)) ?></p>
            </div>
            
            <h3>⚠️ O que acontece se não renovar?</h3>
            <ul>
                <li>Seus imóveis serão <strong>desativados automaticamente</strong></li>
                <li>Você perderá acesso às funcionalidades premium</li>
                <li>Não receberá novos leads dos seus anúncios</li>
            </ul>
            
            <h3>✅ Renove agora e evite interrupções!</h3>
            <p>Mantenha seus anúncios sempre ativos e continue recebendo leads qualificados.</p>
            
            <div style="text-align: center;">
                <a href="<?= site_url('admin/subscription') ?>" class="btn">
                    Renovar Assinatura Agora
                </a>
            </div>
            
            <p style="margin-top: 30px; font-size: 14px; color: #666;">
                <strong>Dica:</strong> Assinaturas anuais têm desconto! Renove por 12 meses e economize.
            </p>
            
            <div class="footer">
                <p>Este é um email automático do sistema de notificações.</p>
                <p>&copy; <?= date('Y') ?> Habitaweb - Todos os direitos reservados</p>
            </div>
        </div>
    </div>
</body>
</html>
