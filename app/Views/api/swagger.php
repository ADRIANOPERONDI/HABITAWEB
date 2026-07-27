<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>API Habitaweb — Documentação</title>

  <!--
    Assets servidos localmente (public/assets/swagger), não de CDN.
    A versão anterior carregava de unpkg.com, o que deixava a página em branco
    em ambiente sem internet, atrás de proxy corporativo ou com CSP restritiva —
    justamente os cenários de quem está integrando a partir de uma rede de empresa.
  -->
  <link rel="stylesheet" href="<?= base_url('assets/swagger/swagger-ui.css') ?>" />
  <link rel="icon" type="image/png" href="<?= base_url('assets/img/favicon.png') ?>" />

  <style>
    body { margin: 0; background: #fafafa; }

    .hw-topbar {
      background: #0f172a;
      color: #f8fafc;
      padding: 18px 24px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .hw-topbar h1 { margin: 0 0 4px; font-size: 19px; font-weight: 600; }
    .hw-topbar p  { margin: 0; font-size: 13px; color: #94a3b8; }
    .hw-topbar a  { color: #7dd3fc; text-decoration: none; }
    .hw-topbar a:hover { text-decoration: underline; }

    .hw-steps {
      display: flex; flex-wrap: wrap; gap: 10px;
      margin-top: 14px; font-size: 12.5px;
    }
    .hw-steps span {
      background: #1e293b; border: 1px solid #334155;
      border-radius: 999px; padding: 5px 12px; color: #cbd5e1;
    }
    .hw-steps b { color: #f8fafc; }

    /* O Swagger UI já traz o título no spec; escondemos o dele para não duplicar. */
    .swagger-ui .topbar { display: none; }
  </style>
</head>
<body>

<div class="hw-topbar">
  <h1>API Habitaweb</h1>
  <p>
    Documentação interativa &middot;
    <a href="<?= site_url('api/docs/json') ?>">openapi.json</a> &middot;
    Clique em <b>Authorize</b> e cole sua API Key (<code>pk_…</code>) para testar de verdade.
  </p>
  <div class="hw-steps">
    <span><b>1.</b> Gere a chave em Admin → API Keys</span>
    <span><b>2.</b> Valide com <b>GET /auth/me</b></span>
    <span><b>3.</b> Sincronize com <b>POST /import/properties</b></span>
  </div>
</div>

<div id="swagger-ui"></div>

<script src="<?= base_url('assets/swagger/swagger-ui-bundle.js') ?>"></script>
<script src="<?= base_url('assets/swagger/swagger-ui-standalone-preset.js') ?>"></script>
<script>
  window.onload = function () {
    window.ui = SwaggerUIBundle({
      url: '<?= site_url('api/docs/json') ?>',
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
      layout: 'BaseLayout',

      // Mantém o token entre recarregamentos — sem isto o integrador precisa
      // colar a credencial de novo a cada F5.
      persistAuthorization: true,

      // "Try it out" já habilitado em todos os endpoints.
      tryItOutEnabled: true,

      docExpansion: 'list',
      defaultModelsExpandDepth: 1,
      defaultModelRendering: 'example',
      displayRequestDuration: true,
      filter: true,
      showCommonExtensions: true,
      syntaxHighlight: { activate: true, theme: 'agate' }
    });
  };
</script>
</body>
</html>
