<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .property-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ef4444; }
        .btn { display: inline-block; padding: 12px 24px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        .alert { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 15px; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⛔ Imóvel Pausado por Inatividade</h1>
        </div>
        <div class="content">
            <p>Olá, <strong><?= esc($account->nome ?? 'Proprietário') ?></strong>,</p>
            
            <div class="alert">
                <strong>⚠️ IMPORTANTE:</strong> Seu imóvel foi <strong>pausado automaticamente</strong> por não ter sido atualizado nos últimos 90 dias.
            </div>
            
            <div class="property-info">
                <h3><?= esc($property->titulo ?? 'Sem título') ?></h3>
                <p><strong>Código:</strong> #<?= $property->id ?></p>
                <p><strong>Tipo:</strong> <?= esc($property->tipo_imovel ?? '') ?></p>
                <p><strong>Localização:</strong> <?= esc($property->cidade ?? '') ?> - <?= esc($property->bairro ?? '') ?></p>
                <p><strong>Última atualização:</strong> <?= date('d/m/Y', strtotime($property->updated_at)) ?></p>
                <p><strong>Status atual:</strong> <span style="color: #ef4444; font-weight: bold;">PAUSADO</span></p>
            </div>
            
            <h3>📌 O que isso significa?</h3>
            <ul>
                <li>Seu imóvel <strong>não está mais visível</strong> nas buscas</li>
                <li>Não receberá novos leads ou visualizações</li>
                <li>Imóveis inativos prejudicam a qualidade do portal</li>
            </ul>
            
            <h3>✅ Como reativar?</h3>
            <p>Acesse o painel, atualize as informações (preço, fotos, descrição) e <strong>clique em "Reativar Imóvel"</strong>. Seu anúncio voltará ao ar imediatamente!</p>
            
            <a href="<?= site_url('admin/properties/' . $property->id . '/edit') ?>" class="btn">
                Reativar Imóvel Agora
            </a>
            
            <p style="margin-top: 30px; font-size: 14px; color: #666;">
                <strong>Dica:</strong> Mantenha seus imóveis sempre atualizados para ter melhor posicionamento nas buscas e receber mais leads qualificados!
            </p>
            
            <div class="footer">
                <p>Este é um email automático do sistema de curadoria.</p>
                <p>&copy; <?= date('Y') ?> Habitaweb - Todos os direitos reservados</p>
            </div>
        </div>
    </div>
</body>
</html>
