# RELATÓRIO DE ANDAMENTO DO PROJETO

**Projeto:** Plataforma Habitaweb  
**Tecnologia:** PHP 8.2+ / CodeIgniter 4 / PostgreSQL  
**Etapa:** 7ª Entrega (Fase 2)  
**Progresso:** 75% da Fase 2  

---

Por meio deste documento, informo o andamento da **Fase 2 (Versão Final)** do projeto **Plataforma Habitaweb**, conforme escopo técnico previamente definido no Anexo I do contrato firmado entre as partes.

---

## Resumo das Entregas Anteriores

| Etapa | Descrição | Status |
|-------|-----------|--------|
| MVP (1-4) | Portal + Admin + Infra + Dashboard | ✅ 100% |
| 5ª | Gateway de Pagamento (Asaas) | ✅ 100% |
| 6ª | Sistema de Planos e Assinaturas | ✅ 100% |
| **7ª** | **Destaques/Upsell + Webhooks** | **Em Desenvolvimento** |

---

## Funcionalidades Desenvolvidas nesta Etapa

### 1. Sistema de Destaques/Upsell (Turbo)

Implementação do sistema de monetização adicional para destacar imóveis:

#### 1.1 Pacotes de Destaque

- **Pacotes Configuráveis**: Administrador define pacotes de turbo
- **Campos do Pacote**:
  - Nome (ex: Turbo 7 dias, Turbo 30 dias)
  - Descrição
  - Preço
  - Duração em dias
  - Posição no ranking (multiplicador de relevância)
  - Badge especial (Destaque, Super Destaque)
  - Ícone/cor diferenciada na listagem

#### 1.2 Painel de Pacotes Turbo (Super Admin)

- **Listagem de Pacotes**: Todos os pacotes disponíveis
- **Criar/Editar**: Formulário completo de configuração
- **Ativar/Desativar**: Toggle de disponibilidade
- **Estatísticas**: Quantidade de vendas por pacote

#### 1.3 Fluxo de Compra (Anunciante)

1. Anunciante acessa "Turbinar Imóvel" no painel
2. Seleciona o imóvel que deseja destacar
3. Escolhe o pacote de turbo
4. Realiza pagamento (cartão, boleto ou PIX)
5. Após confirmação, imóvel é destacado automaticamente

#### 1.4 Exibição de Destaques

- **Ordenação Prioritária**: Imóveis turbinados aparecem primeiro nas buscas
- **Badge Visual**: Selo "Destaque" no card do imóvel
- **Cor Diferenciada**: Borda ou fundo especial na listagem
- **Carrossel de Destaques**: Seção especial na Home para imóveis turbinados

#### 1.5 Controle de Expiração

- **Cron Job Diário**: Verifica imóveis com turbo expirado
- **Remoção Automática**: Remove destaque ao expirar
- **Notificação**: E-mail ao anunciante 2 dias antes de expirar
- **Renovação Fácil**: Link direto para renovar o turbo

---

### 2. Sistema de Webhooks (Retorno Bancário)

Implementação completa do sistema de webhooks para eventos financeiros:

#### 2.1 Webhooks de Pagamento

- **Endpoint Seguro**: `/webhook/asaas` dedicado para receber notificações
- **Validação de Assinatura**: Verificação do header `X-Webhook-Signature` via HMAC
- **Validação de IP**: Aceita apenas requisições dos IPs oficiais do Asaas

#### 2.2 Eventos Processados

| Evento | Ação no Sistema |
|--------|-----------------|
| `PAYMENT_CONFIRMED` | Ativa assinatura / Libera turbo |
| `PAYMENT_RECEIVED` | Registra pagamento recebido |
| `PAYMENT_OVERDUE` | Marca assinatura como inadimplente |
| `PAYMENT_DELETED` | Remove cobrança pendente |
| `PAYMENT_REFUNDED` | Processa reembolso |
| `SUBSCRIPTION_CREATED` | Registra nova assinatura |
| `SUBSCRIPTION_CANCELLED` | Cancela acesso do anunciante |

#### 2.3 Processamento de Eventos

- **Fila de Processamento**: Eventos são enfileirados para evitar timeout
- **Retry Automático**: Em caso de falha, reprocessa em 5, 15 e 60 minutos
- **Logs Detalhados**: Registro completo de cada webhook recebido
- **Idempotência**: Prevenção de processamento duplicado

#### 2.4 Tabela de Logs

- **webhook_logs**: Histórico completo de webhooks recebidos
- **Campos**: ID do evento, tipo, payload, status de processamento, data
- **Visualização no Admin**: Listagem para debug e auditoria

---

### 3. Sistema de Cupons de Desconto

Implementação de cupons promocionais:

#### 3.1 Tipos de Cupom

- **Desconto Percentual**: Ex: 20% de desconto
- **Desconto Fixo**: Ex: R$ 50,00 de desconto
- **Trial Estendido**: Ex: +15 dias de trial gratuito

#### 3.2 Configurações do Cupom

- **Código**: Código digitado pelo cliente (ex: BEMVINDO20)
- **Tipo de Desconto**: Percentual, Fixo ou Trial
- **Valor do Desconto**: Número (% ou R$)
- **Validade**: Data de início e fim
- **Limite de Uso**: Quantidade máxima de utilizações
- **Uso por Cliente**: Limite por usuário (uma vez, ilimitado)
- **Planos Aplicáveis**: Quais planos o cupom funciona

#### 3.3 Aplicação no Checkout

- **Campo de Cupom**: Input visível na tela de pagamento
- **Validação em Tempo Real**: Verifica se cupom é válido ao digitar
- **Desconto Aplicado**: Exibe valor original, desconto e total final
- **Registro de Uso**: Conta uso do cupom para controle de limite

---

## Status Geral — Fase 2

| Módulo | Status | Progresso |
|--------|--------|-----------|
| Etapa 5 (Gateway Asaas) | ✅ Concluído | 100% |
| Etapa 6 (Planos e Assinaturas) | ✅ Concluído | 100% |
| Sistema de Turbo/Destaques | ✅ Concluído | 100% |
| Webhooks Asaas | ✅ Concluído | 100% |
| Sistema de Cupons | ✅ Concluído | 100% |
| **TOTAL FASE 2** | | **75%** |

---

A continuidade da última etapa será realizada conforme cronograma acordado.

---

**Responsável pelo Desenvolvimento:**  
Cristian Dutra de Campos da Silva

**Data:** 05 / 05 / 2026
