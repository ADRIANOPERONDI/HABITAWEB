# Documentação da API Habitaweb v1

Esta API permite a integração de sistemas externos para gestão de imóveis, captura de leads e automação de marketing.

## 📥 Downloads de Especificação
- **Swagger/OpenAPI (JSON):** [openapi.json](/openapi.json)
- **Coleção Postman:** [postman_collection.json](/postman_collection.json)

## 🚀 Como Visualizar
Acesse [http://localhost:8080/api/docs](http://localhost:8080/api/docs) para ver a documentação interativa via Swagger UI.

## Autenticação
A maioria dos endpoints requer autenticação via **API Key** ou **Bearer Token** (for session-based apps).

**Header:**
```http
Authorization: Bearer pk_live_xxxxxxxxxxxxxxxxxxxxxxxx
```

> **Note**: API Keys are managed in the Admin Panel > API Keys.

## Rate Limiting
To ensure stability, requests are limited:
- **Limits**: 5,000 requests/hour per API Key (default).
- **Headers**:
    - `X-RateLimit-Limit`: Total requests allowed per window.
    - `X-RateLimit-Remaining`: Requests remaining.
    - `X-RateLimit-Reset`: Timestamp (Unix) when the window resets.

## Endpoints

### 1. Properties (Imóveis)

#### List Properties
`GET /properties`
- **Filters**: `status`, `cidade`, `bairro`, `min_price`, `max_price`.
- **Response**: List of properties with pagination.

#### Create Property
`POST /properties`
- **Body**: JSON object with property details.
- **Required**: `titulo`, `tipo_imovel`, `finalidade` (venda/locacao).

#### Upload Media (New)
`POST /properties/{id}/media`
- **Content-Type**: `multipart/form-data`
- **Field**: `file` (Image file: jpg, png, webp)
- **Response**:
```json
{
  "success": true,
  "media": {
    "id": 123,
    "url": "https://...",
    "principal": true
  }
}
```

#### Delete Media (New)
`DELETE /properties/{id}/media/{media_id}`
- **Response**: Success message.

#### Set Cover Image (New)
`POST /properties/{id}/media/{media_id}/main`
- **Description**: Sets the specified image as the cover.

### 2. Leads

#### Create Lead (Public)
`POST /leads`
- **Auth**: Not required (Public).
- **Body**:
```json
{
  "property_id": 10,
  "nome_visitante": "João Silva",
  "email_visitante": "joao@email.com",
  "telefone_visitante": "11999999999",
  "mensagem": "Tenho interesse."
}
```

#### List Leads
`GET /leads`
- **Auth**: Required. Lists leads sent to your properties.
- **Filters**: `property_id`, `data_inicio`, `data_fim`.

### 3. Webhooks

#### List Webhooks
`GET /webhooks`

#### Register Webhook
`POST /webhooks`
- **Body**:
```json
{
  "name": "Integration CRM",
  "event": "lead.created",
  "target_url": "https://seu-crm.com/hooks/habitaweb"
}
```
- **Events**: `lead.created`, `property.created`, `property.updated`, `property.closed`

#### Security (Signature)
We send a `X-Webhook-Signature` header (HMAC SHA256 of `timestamp.payload` using your secret).
```php
// Verification Example
$signature = hash_hmac('sha256', "$timestamp.$payload", $secret);
// Compare $signature with header
```
