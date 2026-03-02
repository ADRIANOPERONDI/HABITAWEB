<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= lang('App.email_activation_subject') ?></title>
    <style>
        body { font-family: 'Inter', Helvetica, Arial, sans-serif; background-color: #f4f7fa; margin: 0; padding: 0; -webkit-font-smoothing: antialiased; }
        .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .header { background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); padding: 40px; text-align: center; color: white; }
        .content { padding: 40px; text-align: center; line-height: 1.6; color: #334155; }
        .code-box { background-color: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 12px; padding: 20px; margin: 30px 0; font-size: 32px; font-weight: 800; letter-spacing: 12px; color: #6366f1; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; background-color: #f8fafc; }
        .btn { display: inline-block; padding: 14px 28px; background-color: #6366f1; color: white !important; text-decoration: none; border-radius: 50px; font-weight: 700; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin:0; font-size: 24px;">Habitaweb</h1>
            <p style="margin:10px 0 0 0; opacity: 0.9;"><?= lang('App.email_activation_header') ?></p>
        </div>
        <div class="content">
            <h2 style="color: #1e293b; margin-bottom: 10px;"><?= lang('App.email_activation_welcome') ?></h2>
            <p><?= lang('App.email_activation_main') ?></p>
            
            <div class="code-box">
                <?= $hash ?>
            </div>
            
            <p style="font-size: 14px; color: #64748b;"><?= lang('App.email_activation_footer') ?></p>
            
            <a href="<?= url_to('verify-magic-link') ?>?token=<?= $hash ?>" class="btn"><?= lang('App.email_activation_btn') ?></a>
        </div>
        <div class="footer">
            &copy; <?= date('Y') ?> Habitaweb - <?= lang('App.email_copyright') ?>
        </div>
    </div>
</body>
</html>
