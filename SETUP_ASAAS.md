# Configuração do Gateway de Pagamento Asaas

Este guia detalha todos os passos necessários para configurar a integração com o Asaas no seu portal, tanto para ambiente de **Testes (Sandbox)** quanto para **Produção**.

---

## 1. Configurações no Dashboard do Asaas

### Ambiente de Testes (Sandbox)
1. Crie uma conta em [sandbox.asaas.com](https://sandbox.asaas.com).
2. Vá em **Minha Conta** > **Integração**.
3. Gerar **Chave de API**. Copie este valor.
4. Vá em **Webhooks** > **Configurações**:
   *   **URL do Webhook:** `https://seu-dominio.com/webhook/asaas`
   *   **E-mail:** Seu e-mail para avisos.
   *   **Versão da API:** V3.
   *   **Situação:** Ativo.
   *   **Token de Autenticação:** Crie uma senha forte (Este será o seu `ASAAS_WEBHOOK_SECRET`).
   *   **Eventos:** Selecione pelo menos:
       *   `PAYMENT_RECEIVED` (Pagamento Confirmado)
       *   `PAYMENT_CONFIRMED`
       *   `PAYMENT_DELETED`
       *   `SUBSCRIPTION_DELETED`

### Ambiente de Produção
1. Repita os mesmos passos em [asaas.com](https://asaas.com).
2. Lembre-se que em produção a URL do Webhook **DEVE** ser HTTPS e estar acessível publicamente.

---

## 2. Configurações no Servidor (.env)

No arquivo `.env` da raiz do projeto, adicione ou atualize as seguintes chaves:

```env
# Configurações do Asaas
ASAAS_API_KEY=sua_chave_de_api_aqui
ASAAS_WEBHOOK_SECRET=sua_senha_do_webhook_aqui
# Use 'sandbox' ou 'production'
ASAAS_ENV=sandbox 
```

---

## 3. URLs e Rotas Importantes

*   **Endpoint de Webhook:** `POST /webhook/asaas`
*   **Checkout de Promoção (Turbo):** `/admin/promotions/store/{id}`
*   **Página de Assinatura:** `/admin/subscription`

---

## 4. Banco de Dados

Certifique-se de que todas as migrações foram executadas para criar as tabelas de transação e gateways:

```bash
php spark migrate
```

As tabelas envolvidas são:
*   `payment_transactions`: Registra todas as tentativas e confirmações de pagamento.
*   `payment_gateway_configs`: Armazena as chaves de API de forma criptografada (opcional se usar .env).
*   `promotions`: Registra os pacotes ativos nos imóveis.

---

## 5. Como Testar o Fluxo "Turbo"

1.  Acesse a listagem de imóveis no painel administrativo.
2.  Clique no ícone de **Foguete (Turbinar)**.
3.  Escolha um pacote e clique em **Turbinar Agora**.
4.  O sistema gerará um link de pagamento do Asaas e te mostrará a tela de checkout.
5.  No Sandbox do Asaas:
    *   Acesse o dashboard do Asaas Sandbox.
    *   Localize a cobrança gerada.
    *   Clique em **Confirmar Recebimento Manualmente** (Simulação de pagamento).
6.  O Webhook receberá a confirmação e o imóvel será destacado automaticamente com a estrela.

---

## 6. Consultas de Suporte

Caso o destaque não ocorra, verifique os logs do sistema em `writable/logs/log-YYYY-MM-DD.log`. Procure por:
*   `Asaas API Error`: Problemas na geração do pagamento.
*   `Webhook Signature Invalid`: O `ASAAS_WEBHOOK_SECRET` no `.env` está diferente do que foi configurado no Asaas.
*   `Transação de promoção não encontrada`: Problemas de sincronização de banco de dados.

---

**Desenvolvido com 🚀 por Antigravity (Google DeepMind Team)**
