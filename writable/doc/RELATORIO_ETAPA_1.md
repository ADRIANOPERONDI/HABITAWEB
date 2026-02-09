# RELATÓRIO DE ANDAMENTO DO PROJETO

**Projeto:** Plataforma Habitaweb  
**Tecnologia:** PHP 8.2+ / CodeIgniter 4 / PostgreSQL  
**Etapa:** 1ª Entrega  
**Progresso:** 25% Concluído  

---

Por meio deste documento, informo o andamento do desenvolvimento do projeto **Plataforma Habitaweb**, conforme escopo técnico previamente definido no Anexo I do contrato firmado entre as partes.

---

## 1. Portal Público (Frontend) — 100% desta etapa

### 1.1 Home Page

Desenvolvimento completo da página inicial do portal, contemplando:

- **Barra de Busca Inteligente**: Campo de busca com filtros por Cidade, Tipo de Imóvel (Casa, Apartamento, Comercial, Terreno) e Faixa de Valor, permitindo ao visitante encontrar imóveis de forma rápida e intuitiva.
- **Seção de Destaques**: Área reservada para exibição de imóveis em destaque, com carrossel de imagens e informações resumidas (preço, localização, área).
- **Grid de Imóveis Recentes**: Listagem dos imóveis mais recentes cadastrados na plataforma, exibidos em formato de cards responsivos.
- **Design Responsivo**: Layout adaptável para dispositivos desktop, tablet e mobile, garantindo boa experiência em qualquer tela.
- **Performance Otimizada**: Carregamento assíncrono de imagens (lazy loading) para melhor velocidade de acesso.

### 1.2 Página de Listagem de Imóveis

Implementação da página de resultados de busca com os seguintes recursos:

- **Visualização Grid/Lista**: Alternância entre modo grade (cards) e modo lista (linhas detalhadas), permitindo ao usuário escolher sua preferência de visualização.
- **Paginação**: Sistema de paginação para navegação entre resultados, exibindo 12 imóveis por página.
- **Breadcrumb de Navegação**: Indicador de localização do usuário dentro do site (Home > Imóveis > Venda > São Paulo).
- **Ordenação**: Opções para ordenar resultados por preço (menor/maior), data de publicação e relevância.
- **Contagem de Resultados**: Exibição do total de imóveis encontrados para a busca realizada.

### 1.3 Página de Detalhes do Imóvel

Desenvolvimento da página individual de cada imóvel, incluindo:

- **Galeria de Fotos**: Carrossel de imagens com navegação por setas, miniaturas (thumbnails) e suporte a visualização em tela cheia (lightbox).
- **Informações Principais**: Exibição destacada do título, preço, tipo de negócio (Venda/Aluguel), localização completa (cidade, bairro, endereço).
- **Descrição Detalhada**: Área de texto formatado com a descrição completa do imóvel, preservando parágrafos e formatação.
- **Características do Imóvel**: Listagem estruturada com número de quartos, banheiros, vagas de garagem, área total (m²), área construída (m²).
- **Comodidades**: Checklist visual de itens como piscina, churrasqueira, ar condicionado, mobiliado, etc.
- **Mapa de Localização**: Integração com Google Maps exibindo a localização aproximada do imóvel (por bairro, preservando privacidade do endereço exato).
- **Dados do Anunciante**: Exibição do nome/logo da imobiliária ou corretor responsável pelo anúncio.

### 1.4 Botão "Tenho Interesse" (WhatsApp)

Implementação do sistema de contato direto:

- **Botão Fixo**: Botão flutuante sempre visível na página de detalhes do imóvel.
- **Redirecionamento WhatsApp**: Ao clicar, o visitante é direcionado para o WhatsApp do anunciante com mensagem pré-formatada contendo: nome do imóvel, código de referência e link direto para o anúncio.
- **Registro de Interesse**: Antes do redirecionamento, o sistema registra o lead (contato) no banco de dados para posterior acompanhamento pelo anunciante.

---

## 2. Painel Administrativo (Início) — Fundação

### 2.1 Sistema de Login e Autenticação

Implementação de sistema de autenticação seguro:

- **Tela de Login**: Interface limpa e profissional para acesso ao painel administrativo.
- **Autenticação Segura**: Senhas criptografadas com algoritmo bcrypt, proteção contra ataques de força bruta.
- **Sessões Seguras**: Gerenciamento de sessões com expiração automática por inatividade.
- **Recuperação de Senha**: Fluxo de "Esqueci minha senha" com envio de link por e-mail.

### 2.2 Gestão Básica de Usuários

Sistema inicial de gerenciamento de contas:

- **Cadastro de Usuários**: Formulário de registro com validação de e-mail único, CPF/CNPJ e dados de contato.
- **Níveis de Acesso**: Estrutura preparada para diferentes perfis (Administrador, Imobiliária, Corretor).
- **Listagem de Usuários**: Tabela administrativa com busca, filtros e paginação.

---

## Ambiente de Desenvolvimento

- **Linguagem**: PHP 8.2+
- **Framework**: CodeIgniter 4.x
- **Banco de Dados**: PostgreSQL 15
- **Frontend**: HTML5, CSS3, JavaScript (jQuery), Bootstrap 5.2
- **Versionamento**: Git

---

## Status Geral

| Módulo | Status | Progresso |
|--------|--------|-----------|
| Home Page | ✅ Concluído | 100% |
| Página de Listagem | ✅ Concluído | 100% |
| Página de Detalhes | ✅ Concluído | 100% |
| Botão WhatsApp | ✅ Concluído | 100% |
| Login/Autenticação | ✅ Concluído | 100% |
| Gestão de Usuários | ✅ Concluído | 100% |
| **TOTAL DA ETAPA** | | **25%** |

---

A continuidade do desenvolvimento das próximas etapas será realizada conforme cronograma acordado.

Sem mais para o momento, permaneço à disposição para quaisquer esclarecimentos adicionais.

---

**Responsável pelo Desenvolvimento:**  
Cristian Dutra de Campos da Silva

**Data:** 09 / 02 / 2026
