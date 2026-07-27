# API Habitaweb — Guia de Integração

Guia para parceiros (imobiliárias, corretores e CRMs) integrarem seus sistemas ao Habitaweb.

**Documentação interativa:** `https://SEU-DOMINIO/api/docs` — Swagger UI com "Try it out" habilitado.
**Spec OpenAPI 3.0.3:** `https://SEU-DOMINIO/api/docs/json`
**Coleção Postman:** `https://SEU-DOMINIO/postman_collection.json`

Base de todas as chamadas: `https://SEU-DOMINIO/api/v1`

---

## Índice

1. [Começando em 3 passos](#1-começando-em-3-passos)
2. [Autenticação](#2-autenticação)
3. [Formato de resposta e códigos de erro](#3-formato-de-resposta-e-códigos-de-erro)
4. [Rate limit](#4-rate-limit)
5. [Receita: cadastrar um imóvel com imagens](#5-receita-cadastrar-um-imóvel-com-imagens)
6. [Receita: sincronizar seu catálogo (via de mão dupla)](#6-receita-sincronizar-seu-catálogo-via-de-mão-dupla)
7. [Imagens em detalhe](#7-imagens-em-detalhe)
8. [Leads](#8-leads)
9. [Webhooks](#9-webhooks)
10. [Limites do plano](#10-limites-do-plano)
11. [Referência rápida de endpoints](#11-referência-rápida-de-endpoints)

---

## 1. Começando em 3 passos

**1. Gere sua API Key**
No painel: **Admin → API Keys → Nova chave**. A chave (`pk_live_...`) aparece **uma única vez** — copie e guarde em local seguro. Se perder, revogue e gere outra.

**2. Confirme que a credencial funciona**

```bash
curl https://SEU-DOMINIO/api/v1/auth/me \
  -H "Authorization: Bearer pk_live_SUA_CHAVE"
```

A resposta traz a conta, o plano e o uso atual:

```json
{
  "status": 200,
  "error": null,
  "message": "Success",
  "data": {
    "account": { "id": 42, "nome": "Imobiliária Exemplo", "tipo_conta": "IMOBILIARIA" },
    "auth": { "type": "api_key", "rate_limit": 1000 },
    "plan": { "nome": "Ouro", "chave": "OURO", "limite_imoveis_ativos": 200, "limite_fotos_por_imovel": 20 },
    "usage": { "imoveis_ativos": 37 }
  }
}
```

**3. Envie seu catálogo** → veja a [seção 6](#6-receita-sincronizar-seu-catálogo-via-de-mão-dupla).

---

## 2. Autenticação

Toda requisição autenticada usa:

```
Authorization: Bearer <credencial>
```

Há **duas credenciais aceitas**. Escolha conforme o cenário:

| | **API Key** | **JWT** |
|---|---|---|
| Formato | `pk_live_...` / `pk_test_...` | `eyJhbGciOi...` |
| Validade | até ser revogada | 1 hora (renovável por 30 dias) |
| Como obter | painel admin | `POST /auth/token` |
| Onde usar | integração servidor-a-servidor | app mobile, front-end, terceiros |

> Para a maioria dos parceiros a **API Key basta**. O JWT existe para quando o token precisa trafegar por um cliente menos confiável: se vazar, expira em 1 hora.

### Obtendo um JWT

```bash
curl -X POST https://SEU-DOMINIO/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"api_key": "pk_live_SUA_CHAVE"}'
```

```json
{
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 3600,
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_expires_in": 2592000,
    "account_id": 42
  }
}
```

### Renovando

```bash
curl -X POST https://SEU-DOMINIO/api/v1/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "SEU_REFRESH_TOKEN"}'
```

⚠️ **O refresh token é rotacionado**: o antigo é revogado e você recebe um novo par. Reutilizar um refresh já usado devolve `401 TOKEN_REVOKED` — é a proteção padrão contra roubo de token. Guarde sempre o refresh token mais recente.

### Encerrando

```bash
curl -X POST https://SEU-DOMINIO/api/v1/auth/revoke \
  -H "Content-Type: application/json" \
  -d '{"refresh_token": "SEU_REFRESH_TOKEN"}'
```

O access token já emitido continua válido até expirar (no máximo 1 hora) — é a natureza de um JWT stateless. Para corte imediato, **revogue a API Key** no painel: isso invalida na hora todos os JWTs emitidos a partir dela.

---

## 3. Formato de resposta e códigos de erro

Toda resposta da API — sucesso ou erro — usa o **mesmo envelope**.

**Sucesso:**
```json
{
  "status": 200,
  "error": null,
  "message": "Success",
  "data": { }
}
```

**Erro:**
```json
{
  "status": 422,
  "error": 422,
  "error_code": "VALIDATION_FAILED",
  "message": "Dados do imóvel inválidos.",
  "data": null,
  "details": {
    "preco": "O campo 'preco' é obrigatório.",
    "cidade": "O campo 'cidade' é obrigatório."
  }
}
```

> **Programe contra `error_code`, nunca contra `message`.** O `error_code` é um contrato estável; a `message` é texto em português e pode mudar sem aviso.

### Catálogo de `error_code`

| HTTP | `error_code` | Significado | O que fazer |
|---|---|---|---|
| 400 | `INVALID_PAYLOAD` | JSON malformado ou campo obrigatório ausente no corpo | Corrija o corpo da requisição |
| 401 | `MISSING_TOKEN` | Header `Authorization` ausente | Envie a credencial |
| 401 | `MALFORMED_HEADER` | Formato diferente de `Bearer <token>` | Corrija o header |
| 401 | `INVALID_KEY` | API Key não existe | Confira a chave |
| 401 | `INACTIVE_KEY` | Chave inativa ou expirada | Reative ou gere outra |
| 401 | `TOKEN_EXPIRED` | JWT venceu | Renove via `/auth/refresh` |
| 401 | `TOKEN_SIGNATURE_INVALID` | JWT adulterado | Obtenha um novo token |
| 401 | `TOKEN_WRONG_TYPE` | Usou refresh token como access token | Use o `access_token` |
| 401 | `TOKEN_REVOKED` | Refresh token já usado ou revogado | Refaça o login |
| 401 | `KEY_REVOKED` | A API Key de origem foi revogada | Gere uma nova chave |
| 403 | `TENANT_FORBIDDEN` | O recurso é de outra conta | Confira o ID |
| 404 | `NOT_FOUND` | Recurso ou endpoint inexistente | Confira a URL/ID |
| 409 | `PLAN_LIMIT_REACHED` | Cota de imóveis ativos esgotada | Faça upgrade ou pause imóveis |
| 409 | `PHOTO_LIMIT_REACHED` | Cota de fotos do imóvel esgotada | Remova fotos ou faça upgrade |
| 422 | `VALIDATION_FAILED` | Dados inválidos — veja `details` | Corrija os campos apontados |
| 429 | `RATE_LIMITED` | Limite de requisições excedido | Aguarde `retry_after` segundos |
| 500 | `INTERNAL_ERROR` | Falha no servidor | Tente novamente; persistindo, contate o suporte |

### 207 Multi-Status

Operações em lote (`/import/properties`, `/media/batch`) respondem **207** quando *parte* dos itens falha. O corpo traz o resultado item a item — sempre percorra `results` em vez de confiar apenas no status.

---

## 4. Rate limit

**1.000 requisições por hora** por API Key (ajustável por chave no painel).

Toda resposta traz:

```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987
X-RateLimit-Reset: 1769806800
```

Ao estourar, a API responde **429** com `retry_after` em segundos. Um JWT consome a **mesma cota** da API Key que o emitiu.

Endpoints públicos (sem autenticação) têm limite de **100 req/h por IP**.

---

## 5. Receita: cadastrar um imóvel com imagens

O jeito mais curto — **imóvel e fotos numa única chamada**:

```bash
curl -X POST https://SEU-DOMINIO/api/v1/properties \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{
    "external_id": "CRM-1001",
    "titulo": "Apartamento 3 dormitórios no Centro Histórico",
    "descricao": "Apartamento reformado, andar alto, vista livre.",
    "tipo_negocio": "VENDA",
    "tipo_imovel": "apartamento",
    "preco": 749000,
    "cidade": "Porto Alegre",
    "bairro": "Centro Histórico",
    "estado": "RS",
    "cep": "90020-070",
    "quartos": 3,
    "banheiros": 2,
    "suites": 1,
    "vagas": 1,
    "area_total": 98.5,
    "status": "ACTIVE",
    "images": [
      { "url": "https://cdn.seusite.com.br/1001/sala.jpg", "ordem": 1, "principal": true },
      { "url": "https://cdn.seusite.com.br/1001/cozinha.jpg", "ordem": 2 },
      { "url": "https://cdn.seusite.com.br/1001/quarto.jpg", "ordem": 3 }
    ]
  }'
```

Resposta **201**:

```json
{
  "status": 201,
  "message": "Imóvel criado com sucesso.",
  "data": {
    "property_id": 77,
    "property": { "id": 77, "titulo": "Apartamento 3 dormitórios...", "status": "ACTIVE" },
    "images": {
      "requested": 3,
      "imported": [
        { "id": 981, "url": "https://.../uploads/properties/77/9f2c.jpg", "principal": true }
      ],
      "skipped": 0,
      "errors": []
    }
  }
}
```

### Campos obrigatórios

`titulo`, `tipo_negocio`, `tipo_imovel`, `preco`, `cidade`, `bairro`.

- `tipo_negocio`: `VENDA` | `ALUGUEL` | `TEMPORADA` | `VENDA_ALUGUEL`
- `status`: `DRAFT` (padrão) | `ACTIVE` | `PAUSED` | `SOLD` — **só `ACTIVE` aparece no portal público**
- `estado`: sigla de 2 letras (`RS`, `SP`, …)

### Campos somente-leitura

Estes são **ignorados** se você os enviar — são produto pago ou métrica interna:

`is_destaque`, `highlight_level`, `is_verified`, `verification_status`, `score_qualidade`, `visitas_count`, `leads_count`.

### Se uma foto falhar

O imóvel **é criado do mesmo jeito**. O bloco `images.errors` diz quais URLs falharam e por quê — reenvie só essas via `POST /properties/{id}/media`.

---

## 6. Receita: sincronizar seu catálogo (via de mão dupla)

Esta é a parte que elimina o retrabalho: você mantém seu sistema como fonte da verdade e o Habitaweb acompanha.

### O `external_id` é a chave de tudo

Em cada imóvel, informe o **identificador que ele tem no seu sistema**:

```json
{ "external_id": "CRM-1001", "titulo": "...", "preco": 749000 }
```

O Habitaweb faz *upsert* por `(sua conta, external_id)`:

| Situação | Resultado |
|---|---|
| `external_id` novo | `"action": "created"` |
| `external_id` já sincronizado | `"action": "updated"` |
| sem `external_id` | cria um registro novo **a cada envio** |

> **Reenviar o catálogo inteiro todo dia é seguro.** Nada é duplicado.

### Ida: enviar seus imóveis

```bash
curl -X POST https://SEU-DOMINIO/api/v1/import/properties \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{
    "properties": [
      {
        "external_id": "CRM-1001",
        "titulo": "Apartamento 3 dormitórios no Centro",
        "tipo_negocio": "VENDA", "tipo_imovel": "apartamento",
        "preco": 749000, "cidade": "Porto Alegre", "bairro": "Centro Histórico",
        "images": [{ "url": "https://cdn.seusite.com.br/1001/sala.jpg", "principal": true }]
      },
      {
        "external_id": "CRM-1002",
        "titulo": "Casa com pátio no Ipiranga",
        "tipo_negocio": "VENDA", "tipo_imovel": "casa",
        "preco": 1200000, "cidade": "Porto Alegre", "bairro": "Ipiranga"
      }
    ]
  }'
```

```json
{
  "data": {
    "format": "json",
    "validate_only": false,
    "summary": { "total": 2, "created": 1, "updated": 1, "errors": 0 },
    "results": [
      { "index": 1, "external_id": "CRM-1001", "property_id": 77, "action": "updated",
        "images": { "requested": 1, "imported": 0, "skipped": 1, "errors": [] }, "errors": {} },
      { "index": 2, "external_id": "CRM-1002", "property_id": 78, "action": "created",
        "images": { "requested": 0, "imported": 0, "skipped": 0, "errors": [] }, "errors": {} }
    ]
  }
}
```

Guarde o `property_id` associado ao seu `external_id` — é útil para as chamadas diretas.

**Limite:** 200 imóveis por chamada. Divida catálogos maiores em lotes.

### Testando antes (simulação)

Envie `"validate_only": true`: a API valida tudo e **não grava nada**, devolvendo `would_create` / `would_update` por item. Ideal para o primeiro teste da integração.

### Nomes de campo alternativos

Não precisa reescrever seu payload. Também aceitamos:

| Seu campo | Equivale a |
|---|---|
| `title` / `name` | `titulo` |
| `description` | `descricao` |
| `price` / `amount` | `preco` |
| `city` | `cidade` |
| `neighborhood` / `district` | `bairro` |
| `state` | `estado` |
| `zipcode` / `postal_code` | `cep` |
| `bedrooms` | `quartos` |
| `bathrooms` | `banheiros` |
| `parking` / `garage` | `vagas` |
| `total_area` | `area_total` |
| `operation` / `transaction_type` | `tipo_negocio` |
| `property_type` / `type` | `tipo_imovel` |
| `reference` / `codigo` | `external_id` |
| `photos` / `fotos` / `imagens` | `images` |

Também normalizamos valores: `sale`→`VENDA`, `rent`/`locacao`→`ALUGUEL`, `season`→`TEMPORADA`.

### Volta: puxar o que mudou no Habitaweb

```bash
curl -G https://SEU-DOMINIO/api/v1/export/properties \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  --data-urlencode "format=json" \
  --data-urlencode "updated_since=2026-07-01T00:00:00-03:00" \
  --data-urlencode "per_page=100"
```

Retorna os imóveis com `external_id`, imagens e paginação. Use `updated_since` para trazer **apenas o que mudou** desde a última sincronização.

O resultado pode ser reenviado ao `/import/properties` sem duplicar nada — o ciclo fecha.

### Fluxo recomendado (diário)

```
1. GET  /export/properties?format=json&updated_since=<última execução>
        → reconcilie no seu sistema pelo external_id
2. POST /import/properties  { properties: [ ...os que mudaram do seu lado... ] }
        → confira summary.errors e results[].errors
3. Guarde o horário desta execução para o próximo updated_since
```

### Alternativa: CSV

Se seu sistema exporta CSV, envie como `multipart/form-data`:

```bash
curl -X POST https://SEU-DOMINIO/api/v1/import/properties \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -F "file=@imoveis.csv"
```

Colunas mínimas: `titulo, tipo_negocio, tipo_imovel, preco, cidade, bairro`
Coluna recomendada: `external_id` (sem ela, cada envio cria registros novos)

O cabeçalho é **case-insensitive** e aceita os mesmos aliases da tabela acima. Limite: 1000 linhas e 5 MB por arquivo. Linhas com problema viram erro **daquela linha**, sem derrubar o lote.

---

## 7. Imagens em detalhe

### Três formas de enviar

**a) Junto com o imóvel** (recomendado para sincronização em massa):
```json
{ "titulo": "...", "images": [{ "url": "https://...", "principal": true }] }
```

**b) Por URL, em chamada separada:**
```bash
curl -X POST https://SEU-DOMINIO/api/v1/properties/77/media \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://cdn.seusite.com.br/1001/sala.jpg", "principal": true}'
```

**c) Upload de arquivo (multipart):**
```bash
curl -X POST https://SEU-DOMINIO/api/v1/properties/77/media \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -F "file=@sala.jpg"
```

**Em lote, até 20 URLs:**
```bash
curl -X POST https://SEU-DOMINIO/api/v1/properties/77/media/batch \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"images": [
        {"url": "https://cdn.seusite.com.br/1.jpg", "ordem": 1, "principal": true},
        {"url": "https://cdn.seusite.com.br/2.jpg", "ordem": 2}
      ]}'
```

### Regras

| Item | Regra |
|---|---|
| Formatos | JPG, PNG, WebP |
| Tamanho | até 5 MB (upload) / 10 MB (por URL) |
| Dimensões | entre 100×100 e 12000×12000 px |
| Validação | pelo **conteúdo real** do arquivo, não pela extensão nem pelo `Content-Type` |
| Quantidade | conforme `limite_fotos_por_imovel` do plano |
| URLs | apenas `http`/`https` públicos — endereços de rede interna são recusados |

### O que fazemos com cada imagem

1. Baixamos e validamos que é mesmo uma imagem;
2. **Removemos os metadados EXIF** — incluindo coordenadas GPS e modelo da câmera;
3. Geramos duas variantes: `card` (480px) e `gallery` (1280px);
4. Publicamos no storage e vinculamos ao imóvel.

> Imagens menores que a variante não são ampliadas — nesse caso o portal usa o original.

### Capa

A **primeira** imagem do imóvel vira a capa automaticamente. Para trocar:

```bash
curl -X POST https://SEU-DOMINIO/api/v1/properties/77/media/981/main \
  -H "Authorization: Bearer pk_live_SUA_CHAVE"
```

Sempre existe exatamente uma capa por imóvel.

### Deduplicação

Ingerir a **mesma URL** duas vezes no mesmo imóvel **não** duplica a foto — o retorno marca `skipped`. Isso é o que torna a reimportação diária barata.

### Listar e remover

```bash
curl https://SEU-DOMINIO/api/v1/properties/77/media \
  -H "Authorization: Bearer pk_live_SUA_CHAVE"

curl -X DELETE https://SEU-DOMINIO/api/v1/properties/77/media/981 \
  -H "Authorization: Bearer pk_live_SUA_CHAVE"
```

Remover a capa promove automaticamente a próxima imagem.

---

## 8. Leads

### Receber os leads dos seus anúncios

```bash
curl "https://SEU-DOMINIO/api/v1/leads?status=NOVO" \
  -H "Authorization: Bearer pk_live_SUA_CHAVE"
```

Filtros: `property_id`, `status`, `data_inicio`, `data_fim`, `page`.

### Mover o lead no funil

```bash
curl -X PUT https://SEU-DOMINIO/api/v1/leads/512 \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"status": "EM_ATENDIMENTO"}'
```

Status: `NOVO` → `EM_ATENDIMENTO` → `CONCLUIDO` | `PERDIDO`.
Ao marcar `CONCLUIDO`, `closed_at` é preenchido automaticamente.

### Registrar um lead de um site externo (público)

`POST /api/v1/leads` **não exige autenticação** — use no formulário de contato de um site que exiba imóveis do Habitaweb:

```bash
curl -X POST https://SEU-DOMINIO/api/v1/leads \
  -H "Content-Type: application/json" \
  -d '{"property_id": 77, "nome_visitante": "Maria Silva",
       "email_visitante": "maria@example.com", "telefone_visitante": "51999998888",
       "mensagem": "Gostaria de agendar uma visita."}'
```

---

## 9. Webhooks

Em vez de ficar consultando a API, receba um POST quando algo acontecer.

```bash
curl -X POST https://SEU-DOMINIO/api/v1/webhooks \
  -H "Authorization: Bearer pk_live_SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"name": "Novos leads", "event": "lead.created",
       "target_url": "https://seusite.com.br/webhooks/habitaweb"}'
```

### Eventos disponíveis

| Evento | Quando dispara |
|---|---|
| `lead.created` | Novo lead em um imóvel seu |
| `property.created` | Imóvel criado |
| `property.updated` | Imóvel alterado |
| `property.closed` | Imóvel marcado como vendido/locado |
| `subscription.expiring` | Assinatura próxima do vencimento |

A resposta da criação inclui um `secret` — use-o para validar a autenticidade das entregas. Para trocá-lo: `PUT /webhooks/{id}` com `{"rotate_secret": true}`.

**Testar a entrega:**
```bash
curl -X POST https://SEU-DOMINIO/api/v1/webhooks/9/test \
  -H "Authorization: Bearer pk_live_SUA_CHAVE"
```

> A `target_url` precisa ser um endereço público (`http`/`https`). Endereços de rede interna são recusados por segurança.

---

## 10. Limites do plano

Consulte os seus em `GET /auth/me`.

| Limite | Efeito |
|---|---|
| `limite_imoveis_ativos` | Máximo de imóveis com `status = ACTIVE`. Ao estourar: **409 `PLAN_LIMIT_REACHED`** |
| `limite_fotos_por_imovel` | Máximo de fotos por imóvel. Ao estourar: **409 `PHOTO_LIMIT_REACHED`** |

`null` significa ilimitado.

**Dica para catálogos grandes:** importe com `status: "DRAFT"` (o padrão) — rascunhos não consomem a cota de ativos. Depois ative os que quiser publicar, dentro do limite do plano. Há um teto de segurança para acúmulo de rascunhos; ao atingi-lo, publique ou remova alguns.

---

## 11. Referência rápida de endpoints

🔓 = público (sem autenticação)

### Autenticação
| Método | Endpoint | Descrição |
|---|---|---|
| POST 🔓 | `/auth/token` | Troca API Key por JWT |
| POST 🔓 | `/auth/refresh` | Renova o par de tokens |
| POST 🔓 | `/auth/revoke` | Revoga o refresh token |
| GET | `/auth/me` | Diagnóstico da credencial |

### Sincronização
| Método | Endpoint | Descrição |
|---|---|---|
| POST | `/import/properties` | Importa/sincroniza o catálogo (JSON ou CSV) |
| GET | `/export/properties` | Exporta imóveis (`format=json\|csv\|xlsx\|pdf`) |
| GET | `/export/leads` | Exporta leads |
| GET | `/export/clients` | Exporta clientes |

### Imóveis
| Método | Endpoint | Descrição |
|---|---|---|
| GET | `/properties` | Lista seus imóveis |
| POST | `/properties` | Cadastra imóvel (aceita `images[]`) |
| GET | `/properties/{id}` | Detalha imóvel |
| PUT/PATCH | `/properties/{id}` | Atualiza (parcial) |
| DELETE | `/properties/{id}` | Remove (soft delete) |
| POST | `/properties/{id}/report` | Denuncia imóvel |

### Imagens
| Método | Endpoint | Descrição |
|---|---|---|
| GET | `/properties/{id}/media` | Lista imagens |
| POST | `/properties/{id}/media` | Adiciona (upload ou URL) |
| POST | `/properties/{id}/media/batch` | Adiciona até 20 por URL |
| POST | `/properties/{id}/media/{media_id}/main` | Define a capa |
| DELETE | `/properties/{id}/media/{media_id}` | Remove imagem |

### Leads
| Método | Endpoint | Descrição |
|---|---|---|
| GET | `/leads` | Lista leads |
| POST 🔓 | `/leads` | Registra lead |
| GET | `/leads/{id}` | Detalha lead |
| PUT/PATCH | `/leads/{id}` | Atualiza (status, responsável) |
| DELETE | `/leads/{id}` | Remove lead |

### Contas
| Método | Endpoint | Descrição |
|---|---|---|
| GET | `/accounts` | Lista contas visíveis |
| POST | `/accounts` | Cria subconta (só imobiliária) |
| GET | `/accounts/{id}` | Detalha conta |
| PUT/PATCH | `/accounts/{id}` | Atualiza conta |
| DELETE | `/accounts/{id}` | Remove subconta |

### Webhooks e favoritos
| Método | Endpoint | Descrição |
|---|---|---|
| GET | `/webhooks` | Lista webhooks |
| POST | `/webhooks` | Cadastra webhook |
| GET | `/webhooks/{id}` | Detalha webhook |
| PUT/PATCH | `/webhooks/{id}` | Atualiza webhook |
| DELETE | `/webhooks/{id}` | Remove webhook |
| POST | `/webhooks/{id}/test` | Dispara evento de teste |
| POST | `/favorites/toggle` | Favorita/desfavorita imóvel |

---

## Suporte

- Documentação interativa: `/api/docs`
- Spec OpenAPI: `/api/docs/json`
- Coleção Postman: `/postman_collection.json`

Ao relatar um problema, inclua: endpoint, `error_code`, horário aproximado e os 8 primeiros caracteres da sua API Key (`pk_live_`) — **nunca a chave completa**.
