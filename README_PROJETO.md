Sistema inteiro pra sua região, já pensando no futuro com **API-first, planos PF/Corretor/Imobiliária, turbinar anúncios, integrações externas, ranking inteligente, leads, e diferenciais que o ZAP não faz bem**.

> ✅ Stack alvo: **PHP 8+ + CodeIgniter 4 + PostgreSQL + jQuery + Bootstrap 5.3**
> ✅ Arquitetura obrigatória: **Controller → Service → Model (Entity)**
> ✅ Comentários e textos 100% em português
> ✅ Banco **PostgreSQL** com migrations completas

---

# Portal de Imóveis Regional (API-First) — Sistema Completo (CodeIgniter 4 + PostgreSQL)

## 1) Objetivo do Sistema
Criar um portal de imóveis regional com foco em:
- Compra e aluguel de imóveis
- Anúncios por Pessoa Física (PF), Corretor e Imobiliária
- Planos com limite de imóveis (Start/Pro/Ilimitado)
- Destaques e “Turbo” de anúncios (monetização adicional)
- Sistema de Leads (WhatsApp + formulário + rastreamento)
- API-first para integrações futuras (importação/exportação, parceiros, app mobile)
- Diferenciais locais (curadoria, qualidade, suporte humano, anti-anúncio velho)

O sistema deve ser escalável e preparado para virar um “Hub regional de distribuição + captação + gestão de leads”.

---

## 2) Regras de Negócio (Obrigatórias)

### 2.1 Tipos de Conta (Account Type)
- PF (Pessoa Física)
- Corretor (Profissional)
- Imobiliária (PJ)

### 2.2 Planos (Subscription Plan)
- Plano Start: até 20 imóveis ativos
- Plano Pro: até 80 imóveis ativos
- Plano Imobiliária: ilimitado (sem limite de imóveis ativos)

> IMPORTANTE: o limite conta apenas imóveis com status ATIVO.

### 2.3 Regras de Limite
- Rascunho (DRAFT) não conta no limite.
- Pausado (PAUSED) não conta no limite.
- Vendido/Alugado (SOLD/RENTED) não conta no limite.

### 2.4 Status do Imóvel
- DRAFT (rascunho)
- ACTIVE (ativo)
- PAUSED (pausado)
- SOLD (vendido)
- RENTED (alugado)
- DELETED (removido lógico)

### 2.5 Regras de Assinatura
- Se assinatura expirar ou estiver inadimplente:
  - Não permite ativar novos imóveis
  - Pode pausar imóveis automaticamente
  - Painel exibe alerta de cobrança
- Imobiliária pode ter múltiplos usuários (subcontas)

### 2.6 Turbo / Destaques (Monetização)
Os anúncios podem receber “promoções” com diferentes níveis:
- DESTAQUE (melhora posição e marca visual)
- SUPER_DESTAQUE (prioridade maior)
- VITRINE (home / blocos especiais)
- URGENTE (selo e prioridade)
- TOPO_BAIRRO (topo da busca por bairro/cidade)

O turbo pode ser cobrado por:
- Pacote de dias (ex: 7, 15, 30)
- Mensal
- Créditos (saldo interno)

### 2.7 Curadoria e Qualidade (Diferencial Regional)
O sistema deve oferecer mecanismos para reduzir “anúncio lixo”:
- Marcar anúncios duplicados (mesmo endereço + mesmo preço + mesmas fotos)
- Aviso de preço suspeito (fora do padrão do bairro)
- Expiração de anúncio velho:
  - Se não houver atualização por X dias → status vira “PRECISA_REVISAR”
  - Se continuar sem atualização → PAUSADO automaticamente
- Sistema de denúncia e auditoria manual

### 2.8 Leads (Captação e Rastreamento)
Tipos:
- Clique no WhatsApp
- Formulário de contato (nome/telefone/mensagem)
- Clique no telefone
- Evento de “ver número”
- Evento de “favoritar”

Cada lead deve registrar:
- Imóvel
- Conta anunciante
- Corretor responsável (quando aplicável)
- Origem (página do imóvel, lista, vitrine, etc.)
- Dados do visitante (nome/telefone/email quando existir)
- Datas e eventos

---

## 3) Diferenciais (o que o ZAP não faz bem e você vai dominar)

### 3.1 Curadoria Regional (Qualidade > Quantidade)
- Portal focado em anúncios reais e atualizados
- Melhor experiência pro usuário final (menos golpe / menos lixo)
- Melhor conversão para imobiliárias e corretores

### 3.2 Suporte Humano (Local)
- WhatsApp suporte e onboarding (anunciante publica melhor)
- Ajuda a melhorar anúncios (fotos, descrição, preço)
- Isso vira retenção de assinatura

### 3.3 Ranking “Justo” + Performance
Não é só “quem paga aparece”.
Ranking deve considerar:
- Qualidade do anúncio (completude, fotos, descrição)
- Tempo de resposta do anunciante
- Taxa de conversão (leads / visitas)
- Atualização recente
- Turbo como complemento, não como “único fator”

### 3.4 Anti-Anúncio Velho
- Workflow que impede o portal virar cemitério
- Tudo sempre atualizado e confiável

---

## 4) Arquitetura Obrigatória (CodeIgniter 4)

### 4.1 Padrão de Camadas
**Controller → Service → Model → Entity**
- Controller: apenas valida request e chama Service
- Service: regras de negócio, orquestração, transações
- Model: acesso ao banco, queries, filtros, paginação
- Entity: manipulação de dados e casts

PROIBIDO:
- SQL no Controller
- Lógica de negócio pesada no Controller
- Query manual dentro do Controller

### 4.2 Estrutura de Pastas Sugerida
/app
  /Controllers
    Api/
    Web/
  /Services
  /Models
  /Entities
  /Database
    /Migrations
    /Seeds
  /Helpers
  /Libraries
/public
  /assets
    /js
    /css

### 4.3 Autenticação
- JWT para API (Bearer Token)
- Sessão para Web Admin/Painel
- Refresh token (opcional futuro)

### 4.4 API-First
Tudo que o painel faz deve existir via API:
- Criar imóvel
- Editar imóvel
- Publicar/pausar
- Enviar mídia
- Consultar leads
- Consultar assinatura e limites

---

## 5) Banco de Dados (PostgreSQL) — COMPLETO

### 5.1 Convenções
- Chave primária: BIGSERIAL
- Soft delete: deleted_at (timestamp null)
- Auditoria: created_at / updated_at
- Campos texto: TEXT
- Status via VARCHAR (com valores controlados)

### 5.2 Tabelas Obrigatórias

#### 5.2.1 accounts (Conta / Organização)
Representa PF, Corretor ou Imobiliária.
- PF pode ter 1 usuário
- Imobiliária pode ter vários usuários

#### 5.2.2 users
Usuários da conta.

#### 5.2.3 roles e user_roles
Controle de permissões.

#### 5.2.4 plans (Planos)
Start, Pro, Imobiliária.

#### 5.2.5 subscriptions (Assinaturas)
Assinatura ativa da conta.

#### 5.2.6 properties (Imóveis)
Cadastro principal do imóvel.

#### 5.2.7 property_media (Fotos/Vídeos)
Mídias do imóvel.

#### 5.2.8 property_features (Características)
Ex: piscina, elevador, mobiliado, etc.

#### 5.2.9 property_favorites
Favoritos do usuário visitante (se logado) ou por cookie (se futuro).

#### 5.2.10 leads
Registro de leads.

#### 5.2.11 lead_events
Eventos de lead (WhatsApp, call, form submit, etc.)

#### 5.2.12 promotions (Turbo)
Promoções ativas por imóvel.

#### 5.2.13 promotion_packages
Pacotes de turbo disponíveis.

#### 5.2.14 integration_tokens (Tokens de Integração)
Para integrações externas por API.

#### 5.2.15 webhooks
Webhooks configurados para integração (status imóvel, atualização preço, etc.)

---

## 6) Migrations PostgreSQL (SQL completo)

> Observação: pode ser implementado via Migrations do CodeIgniter 4 (PHP) OU executado SQL direto.
> Abaixo está a base SQL.

```sql
-- [Conteúdo SQL omitido nesta restauração parcial - ver histórico se necessário]
```
