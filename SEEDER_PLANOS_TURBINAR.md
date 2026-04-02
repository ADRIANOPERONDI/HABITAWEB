# Seeder de Planos e Pacotes de Turbinar

## ✅ Status: Implementado e Testado

As seeders foram criadas e testadas com sucesso. Todos os planos e pacotes de turbinar estão sendo corretamente inseridos no banco de dados.

## Alterações Realizadas

### 1. Planos de Assinatura (PlanSeeder.php)

Os seguintes planos foram criados e substituem os antigos:

#### **Plano Prata**
- **Chave**: PRATA
- **Preço Mensal**: R$ 1.850,00
- **Preço Anual**: R$ 1.599,90
- **Limite de Anúncios**: 45
- **Limite de Turbos/mês**: 10
- **Requests API/dia**: 1.000

#### **Plano Ouro**
- **Chave**: OURO
- **Preço Mensal**: R$ 2.850,00
- **Preço Anual**: R$ 2.599,90
- **Limite de Anúncios**: 89
- **Limite de Turbos/mês**: 15
- **Requests API/dia**: 5.000

#### **Plano Diamante**
- **Chave**: DIAMANTE
- **Preço Mensal**: R$ 4.250,00
- **Preço Anual**: R$ 3.999,90
- **Limite de Anúncios**: Ilimitado
- **Limite de Turbos/mês**: Ilimitado
- **Requests API/dia**: 50.000

### 2. Pacotes de Turbinar (PromotionPackageSeeder.php)

#### **Turbinar Imóvel - 7 dias**
- **Chave**: TURBO_7_DIAS
- **Preço**: R$ 50,00
- **Duração**: 7 dias
- **Tipo**: TURBO_IMOVEL

#### **Lead - Compra**
- **Chave**: LEAD_COMPRA
- **Preço**: R$ 80,00
- **Tipo**: LEAD (por unidade)

#### **Lead - Aluguel**
- **Chave**: LEAD_ALUGUEL
- **Preço**: R$ 40,00
- **Tipo**: LEAD (por unidade)

## Como Executar as Seeders

### ✅ Opção 1: Executar apenas as seeders de planos e turbinadores (RECOMENDADO)

```bash
# Executar PlanSeeder
php spark db:seed PlanSeeder

# Executar PromotionPackageSeeder
php spark db:seed PromotionPackageSeeder

# Ou ambas com uma única linha:
php spark db:seed MainSeeder
```

### Opção 2: Resetar banco e popular com dados iniciais

```bash
# Limpa e reexecuta todas as migrations + seeders
php spark migrate:refresh --seed
```

## Verificar os Dados

Para verificar se os planos e pacotes foram criados corretamente:

```bash
php spark db:verify-seeders
```

Ou verificar tabelas específicas:

```bash
# Verificar planos
php spark db:check-plans

# Verificar pacotes de turbinar
php spark db:check-promotions
```

## ⚠️ Notas Importantes

1. **Remove dados antigos**: As seeders usam `truncate()`, então qualquer plano ou pacote anterior será apagado.

2. **Backup recomendado**: Antes de executar em produção, faça backup do banco de dados.

3. **Contas de teste**: por padrão, a MainSeeder não cria contas de teste para evitar erros. Se desejar criar contas teste, descomente as linhas no final de `MainSeeder.php`.

4. **Campos não utilizados**: Os campos `limite_fotos_por_imovel`, `destaques_mensais`, `ativo` e `descricao` não foram preenchidos nas seeders porque estão em migrations posteriores e podem não existir dependendo do estado do banco. To use them, uncomment os valores nas seeders após verificar que as migrations foram aplicadas.

## Estrutura do Banco de Dados

### Tabela: `plans`
- `id` - ID único
- `chave` - Código do plano (PRATA, OURO, DIAMANTE)
- `nome` - Nome exibível
- `limite_imoveis_ativos` - Quantidade máxima de imóveis ativos
- `limite_turbo_mensal` - Limite de turbinares por mês
- `limite_api_requests_dia` - Limite de requisições API
- `preco_mensal` - Preço da assinatura mensal
- `preco_anual` - Preço da assinatura anual
- `created_at` - Data de criação
- `updated_at` - Data de atualização
- `deleted_at` - Data de exclusão (soft delete)

### Tabela: `promotion_packages`
- `id` - ID único
- `chave` - Código do pacote
- `nome` - Nome exibível
- `tipo_promocao` - Tipo (TURBO_IMOVEL, LEAD)
- `duracao_dias` - Duração em dias (0 para leads de unidade)
- `preco` - Preço do pacote
- `created_at` - Data de criação
- `updated_at` - Data de atualização

## Arquivos Modificados

1. **[app/Database/Seeds/PlanSeeder.php](app/Database/Seeds/PlanSeeder.php)** - Seeder dos planos
2. **[app/Database/Seeds/PromotionPackageSeeder.php](app/Database/Seeds/PromotionPackageSeeder.php)** - Seeder dos pacotes de turbinar
3. **[app/Database/Seeds/MainSeeder.php](app/Database/Seeds/MainSeeder.php)** - Seeder principal (chamada as outras)
4. **[app/Commands/VerifySeederDataCommand.php](app/Commands/VerifySeederDataCommand.php)** - Comando CLI para verificar dados
5. **[app/Commands/CheckPlanTableCommand.php](app/Commands/CheckPlanTableCommand.php)** - Comando CLI para inspecionar tabela de planos
6. **[app/Commands/CheckPromotionTableCommand.php](app/Commands/CheckPromotionTableCommand.php)** - Comando CLI para inspecionar tabela de pacotes

## Testado ✅

- ✅ Seeders de planos criados corretamente
- ✅ Seeders de pacotes de turbinar criados corretamente
- ✅ Todos os valores estão corretos
- ✅ Execução sem erros
- ✅ Dados verificáveis via CLI



