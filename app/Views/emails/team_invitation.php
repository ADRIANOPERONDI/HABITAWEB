<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1e293b; margin: 0; padding: 0; background-color: #f8fafc; }
        .container { max-width: 600px; margin: 40px auto; padding: 40px; background-color: #ffffff; border-radius: 16px; shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .logo { font-size: 24px; font-weight: 800; color: #6366f1; text-decoration: none; }
        .content { margin-bottom: 30px; }
        .welcome-title { font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 16px; }
        .credentials-box { background-color: #f1f5f9; padding: 24px; border-radius: 12px; margin: 24px 0; }
        .credential-item { margin-bottom: 12px; }
        .label { font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 4px; }
        .value { font-family: monospace; font-size: 16px; font-weight: 600; color: #334155; }
        .btn { display: inline-block; padding: 12px 32px; background-color: #6366f1; color: #ffffff !important; text-decoration: none; border-radius: 9999px; font-weight: 600; text-align: center; }
        .footer { text-align: center; font-size: 14px; color: #94a3b8; margin-top: 40px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">Habitaweb</div>
        </div>

        <div class="content">
            <h1 class="welcome-title">Bem-vindo à Equipe!</h1>
            <p>Olá, <strong><?= esc($nome) ?></strong>!</p>
            <p>Você foi convidado para fazer parte da equipe da imobiliária no <strong>Habitaweb</strong>. Seus dados de acesso já estão prontos:</p>

            <div class="credentials-box">
                <div class="credential-item">
                    <div class="label">E-mail de Acesso</div>
                    <div class="value"><?= esc($email) ?></div>
                </div>
                <div class="credential-item">
                    <div class="label">Senha Temporária</div>
                    <div class="value"><?= esc($password) ?></div>
                </div>
            </div>

            <p>Para sua segurança, recomendamos que você altere sua senha no seu primeiro acesso através das configurações de perfil.</p>

            <div style="text-align: center; margin-top: 32px;">
                <a href="<?= site_url('admin/login') ?>" class="btn">Acessar Painel Admin</a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; <?= date('Y') ?> Habitaweb. Todos os direitos reservados.</p>
            <p>Este é um e-mail automático, por favor não responda.</p>
        </div>
    </div>
</body>
</html>
