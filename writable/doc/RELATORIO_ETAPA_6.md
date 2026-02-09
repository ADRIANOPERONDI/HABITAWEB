# RELATÓRIO DE ANDAMENTO DO PROJETO

**Projeto:** Plataforma Habitaweb  
**Tecnologia:** PHP 8.2+ / CodeIgniter 4 / PostgreSQL  
**Etapa:** 6ª Entrega (Fase 2)  
**Progresso:** 50% da Fase 2  

---

Por meio deste documento, informo o andamento da **Fase 2 (Versão Final)** do projeto **Plataforma Habitaweb**, conforme escopo técnico previamente definido no Anexo I do contrato firmado entre as partes.

---

## Resumo das Entregas Anteriores

| Etapa | Descrição | Status |
|-------|-----------|--------|
| MVP (1-4) | Portal + Admin + Infra + Dashboard | ✅ 100% |
| 5ª | Gateway de Pagamento (Asaas) | ✅ 100% |
| **6ª** | **Sistema de Planos e Assinaturas** | **Em Desenvolvimento** |

---

## Funcionalidades Desenvolvidas nesta Etapa

### 1. Sistema de Planos

Implementação completa do gerenciamento de planos de assinatura:

#### 1.1 Estrutura de Planos

- **Planos Configuráveis**: Criação ilimitada de planos com diferentes benefícios
- **Campos do Plano**:
  - Nome (ex: Básico, Profissional, Premium)
  - Descrição detalhada
  - Preço mensal
  - Período de teste (trial em dias)
  - Limite de imóveis ativos
  - Limite de fotos por imóvel
  - Limite de destaques simultâneos
  - Acesso a relatórios avançados (sim/não)
  - Acesso à API (sim/não)
  - Suporte prioritário (sim/não)

#### 1.2 Painel de Gestão de Planos (Super Admin)

- **Listagem de Planos**: Tabela com todos os planos cadastrados
- **Criar Novo Plano**: Formulário completo para adicionar planos
- **Editar Plano**: Modificação de valores e limites
- **Ativar/Desativar**: Toggle para disponibilizar ou ocultar plano
- **Ordenação**: Drag-and-drop para ordenar exibição no checkout

#### 1.3 Exibição Pública de Planos

- **Página de Planos**: Comparativo visual entre planos disponíveis
- **Cards de Plano**: Destaque para o plano recomendado
- **Benefícios Listados**: Checklist visual de cada funcionalidade inclusa
- **Call-to-Action**: Botão "Assinar" direcionando para checkout

---

### 2. Sistema de Assinaturas

Controle completo do ciclo de vida das assinaturas:

#### 2.1 Criação de Assinatura

- **Fluxo de Contratação**:
  1. Usuário seleciona plano
  2. Preenche dados de pagamento
  3. Sistema cria assinatura no Asaas
  4. Primeira cobrança é gerada
  5. Acesso liberado após confirmação

- **Período de Trial**: Dias gratuitos antes da primeira cobrança (configurável por plano)
- **Recorrência Automática**: Cobranças mensais automáticas via cartão ou boleto

#### 2.2 Gestão de Assinatura (Anunciante)

- **Minha Assinatura**: Página dedicada no painel do anunciante
- **Informações Exibidas**:
  - Plano atual
  - Status (Ativo, Trial, Pendente, Cancelado)
  - Próxima cobrança
  - Histórico de faturas
  - Forma de pagamento cadastrada

- **Ações Disponíveis**:
  - Upgrade de plano (migração para plano superior)
  - Downgrade de plano (migração para plano inferior no próximo ciclo)
  - Cancelar assinatura
  - Atualizar forma de pagamento

#### 2.3 Gestão de Assinaturas (Super Admin)

- **Listagem de Assinaturas**: Todas as assinaturas do sistema
- **Filtros**: Por status, plano, data, inadimplência
- **Ações Administrativas**:
  - Suspender assinatura
  - Reativar assinatura
  - Forçar upgrade/downgrade
  - Cancelar assinatura
  - Aplicar desconto pontual

#### 2.4 Histórico de Faturas

- **Listagem de Cobranças**: Todas as faturas da assinatura
- **Status Visual**: Paga, Pendente, Vencida, Cancelada
- **Download de Comprovante**: PDF da fatura para fins contábeis
- **Reenvio de Boleto**: Para cobranças pendentes via boleto

---

### 3. Controle de Limites por Plano

Implementação das restrições baseadas no plano contratado:

#### 3.1 Limites Configurados

| Recurso | Básico | Profissional | Premium |
|---------|--------|--------------|---------|
| Imóveis Ativos | 5 | 20 | Ilimitado |
| Fotos por Imóvel | 10 | 30 | 50 |
| Destaques | 1 | 5 | 15 |
| Relatórios | Básico | Avançado | Avançado |
| API | ❌ | ✅ | ✅ |

#### 3.2 Validação de Limites

- **Ao Publicar Imóvel**: Verificação do limite de imóveis ativos
- **Ao Fazer Upload**: Verificação do limite de fotos
- **Ao Destacar**: Verificação do limite de destaques
- **Mensagens Claras**: Feedback informando o limite e sugerindo upgrade

---

## Status Geral — Fase 2

| Módulo | Status | Progresso |
|--------|--------|-----------|
| Etapa 5 (Gateway Asaas) | ✅ Concluído | 100% |
| Gestão de Planos | ✅ Concluído | 100% |
| Sistema de Assinaturas | ✅ Concluído | 100% |
| Controle de Limites | ✅ Concluído | 100% |
| Histórico de Faturas | ✅ Concluído | 100% |
| **TOTAL FASE 2** | | **50%** |

---

A continuidade das próximas etapas será realizada conforme cronograma acordado.

---

**Responsável pelo Desenvolvimento:**  
Cristian Dutra de Campos da Silva

**Data:** 21 / 04 / 2026
