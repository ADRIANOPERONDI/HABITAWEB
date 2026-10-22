<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: <?= $atrasada ? 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)' : 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)' ?>; color: white; padding: 26px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 22px; }
        .content { background: #f9fafb; padding: 28px; border-radius: 0 0 8px 8px; }
        .info-box { background: white; padding: 18px 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid <?= $atrasada ? '#ef4444' : '#6366f1' ?>; }
        .info-box p { margin: 4px 0; }
        .btn { display: inline-block; padding: 14px 28px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin: 8px 4px 0; font-weight: bold; }
        .btn.secondary { background: #ffffff; color: #4f46e5; border: 1px solid #c7d2fe; }
        .footer { text-align: center; margin-top: 28px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?= esc($titulo) ?></h1>
        </div>
        <div class="content">
            <p>Olá, <strong><?= esc($account->nome ?? 'anunciante') ?></strong>.</p>
            <p><?= esc($texto) ?></p>

            <div class="info-box">
                <?php if ($descricao !== ''): ?>
                    <p><strong>Cobrança:</strong> <?= esc($descricao) ?></p>
                <?php endif; ?>
                <p><strong>Valor:</strong> <?= esc($valor) ?></p>
                <p><strong>Vencimento:</strong> <?= esc($vencimento) ?></p>
                <p><strong>Formas de pagamento:</strong> PIX ou boleto</p>
            </div>

            <div style="text-align: center;">
                <?php if ($faturaUrl !== ''): ?>
                    <a href="<?= esc($faturaUrl, 'attr') ?>" class="btn">Pagar agora</a>
                <?php endif; ?>
                <a href="<?= site_url('admin/subscription') ?>" class="btn <?= $faturaUrl !== '' ? 'secondary' : '' ?>">Ver minhas cobranças</a>
            </div>

            <p style="margin-top: 24px; font-size: 13px; color: #666;">Se você já pagou, desconsidere este aviso: a baixa é automática e pode levar alguns minutos.</p>

            <div class="footer">
                <p>Este é um e-mail automático, não é preciso responder.</p>
                <p>&copy; <?= date('Y') ?> Habitaweb</p>
            </div>
        </div>
    </div>
</body>
</html>
