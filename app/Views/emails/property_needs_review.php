<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .property-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #f59e0b; }
        .btn { display: inline-block; padding: 12px 24px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Atenção: Imóvel Precisa de Atualização</h1>
        </div>
        <div class="content">
            <p>Olá, <strong><?= esc($account->nome ?? 'Proprietário') ?></strong>!</p>
            
            <p>Identificamos que o seu imóvel <strong>não foi atualizado nos últimos 60 dias</strong> e está marcado como <strong>PRECISA REVISÃO</strong>.</p>
            
            <div class="property-info">
                <h3><?= esc($property->titulo ?? 'Sem título') ?></h3>
                <p><strong>Código:</strong> #<?= $property->id ?></p>
                <p><strong>Tipo:</strong> <?= esc($property->tipo_imovel ?? '') ?></p>
                <p><strong>Localização:</strong> <?= esc($property->cidade ?? '') ?> - <?= esc($property->bairro ?? '') ?></p>
                <p><strong>Última atualização:</strong> <?= date('d/m/Y', strtotime($property->updated_at)) ?></p>
            </div>
            
            <h3>⏰ O que acontece agora?</h3>
            <ul>
                <li>Seu imóvel continuará ativo por mais <strong>30 dias</strong></li>
                <li>Após 90 dias sem atualização, <strong>será pausado automaticamente</strong></li>
                <li>Imóveis atualizados têm <strong>maior destaque</strong> nas buscas</li>
            </ul>
            
            <h3>✅ Como resolver?</h3>
            <p>Acesse o painel e atualize as informações do seu imóvel (preço, descrição, fotos, etc.). Qualquer edição reseta o contador!</p>
            
            <a href="<?= site_url('admin/properties/' . $property->id . '/edit') ?>" class="btn">
                Atualizar Imóvel Agora
            </a>
            
            <div class="footer">
                <p>Este é um email automático do sistema de curadoria.</p>
                <p>&copy; <?= date('Y') ?> Habitaweb - Todos os direitos reservados</p>
            </div>
        </div>
    </div>
</body>
</html>
