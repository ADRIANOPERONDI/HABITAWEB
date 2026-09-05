# Integração Habitaweb ⇄ Simob

Guia operacional: como ligar uma imobiliária que já usa o **Simob** (Flexpro
Sistemas) ao Habitaweb, o que esperar de cada etapa e o que fazer quando algo
não funciona.

Documentação da API de origem:
<https://documenter.getpostman.com/view/1724124/TVRecVa8>

---

## O que a integração faz (e o que não faz)

| | |
|---|---|
| ✅ Traz o catálogo do Simob para o Habitaweb | automático, a cada 30 min |
| ✅ Traz as fotos dos imóveis | opcional, ligável por tenant |
| ✅ Devolve os leads do portal para o SimobCRM | via "interesse", com retry |
| ✅ Apura comissão por negócio fechado | quando há regra cadastrada |
| ❌ Publicar no Simob um imóvel criado no Habitaweb | **a API do Simob não permite** |
| ❌ Editar no Habitaweb um imóvel que veio do Simob | o Simob é a fonte da verdade |

A última linha é a que mais gera dúvida no suporte: imóvel sincronizado é
**espelho**. Os campos que vêm da origem ficam travados na tela de edição, e o
que o corretor mudar ali seria desfeito na sincronização seguinte. Alterações
se fazem no Simob.

Continuam editáveis no Habitaweb os campos que o Simob não fornece: destaque,
corretor responsável, meta tags de SEO e os campos de curadoria.

---

## O que pedir para a imobiliária

Dois dados, ambos disponíveis no próprio Simob:

1. **URL da imobiliária** — o endereço do Simob dela, algo como
   `https://nomedaimobiliaria.simob.com.br`. Sem barra no final.
2. **Token de integração** — no Simob: **Principal › Sistema › Configurações ›
   aba Integrações**.

Há um terceiro campo, **Chave de codificação JWT**, que é opcional e **não é
usada** na sincronização de imóveis nem no envio de leads. Ela só faz falta para
os endpoints de pessoa, contrato e boleto, que estão fora do escopo atual. Deixe
em branco.

---

## Passo a passo do onboarding

### 1. Configurar

`/admin/integracoes` → **Simob (Flexpro)** → **Configurar**.

Cole a URL e o token, escolha as opções de sincronização e salve:

- **O que importar** — venda, locação, ou os dois.
- **Como o imóvel novo entra** — comece em **Rascunho**. Assim o catálogo não vai
  ao ar antes de você conferir os mapeamentos. Depois de validar, mude para
  Publicado e rode de novo.
- **Importar fotos** — ligado na maioria dos casos. A primeira sincronização de
  um catálogo grande baixa muita imagem; se a janela for apertada, deixe
  desligado na primeira rodada e ligue depois.
- **Máximo de fotos por imóvel** — 20 por padrão.

### 2. Testar conexão

Botão **Testar conexão**. O esperado é:

> Conectado ao Simob: N categoria(s) de imóvel encontrada(s).

Esse teste faz três coisas de uma vez: confirma que a URL responde, que o token é
aceito, e traz as categorias e características da imobiliária para montar o
de/para.

### 3. Revisar os mapeamentos

**Mapeamentos**, na coluna da direita.

Esta é a etapa que exige atenção humana e é o principal custo de cada onboarding.
No Simob, cada imobiliária cria os próprios códigos de categoria e de
característica — "Dormitório(s)" é o código 41 numa e 249 em outra. Não existe
tabela universal.

O sistema chuta pelo nome e marca em **amarelo** o que ainda não foi revisado.
Percorra as duas tabelas:

- **Tipos de imóvel** — de/para entre a categoria do Simob e o tipo do Habitaweb.
  O que ficar como "Não importar" simplesmente não entra no portal.
- **Características** — para qual campo cada atributo vai (quartos, vagas, área…).
  O que não for mapeado **não se perde**: vai para o fim da descrição do imóvel,
  no formato `CARACTERÍSTICA: valor`.

Salve. As linhas deixam de ficar amarelas.

> Se a imobiliária criar uma categoria nova depois, use **Buscar novidades na
> origem** nessa mesma tela. Ele só acrescenta o que falta — nunca desfaz uma
> escolha já feita.

### 4. Primeira sincronização

**Sincronizar agora**. Em catálogo grande leva alguns minutos.

Ao final aparece o resumo: quantos criados, atualizados, sem alteração, pausados,
quantas fotos e quantos erros. O histórico completo fica em **Histórico de
sincronizações**.

Confira alguns imóveis em `/admin/imoveis` — eles trazem o aviso de "Imóvel
sincronizado" na edição.

### 5. Ligar o automático

**Ativar sincronização automática**. A partir daí o cron cuida do resto, de 30 em
30 minutos.

---

## Como funciona por dentro

### Sincronização de imóveis

Roda por `php spark integration:sync`, de 30 em 30 minutos.

A primeira execução varre o catálogo inteiro. As seguintes são **incrementais**:
a API do Simob não tem filtro por data de alteração, então o sync pede o catálogo
ordenado da atualização mais recente para a mais antiga e para de paginar assim
que chega em conteúdo anterior à última rodada.

Imóvel cuja data de alteração não mudou é pulado **sem sequer buscar o detalhe** —
é o que faz uma rodada sem novidade custar poucas requisições em vez de uma por
imóvel.

**Imóvel que some do catálogo é pausado, nunca apagado.** Ele pode ter leads e
histórico, e o sumiço costuma ser temporário. O pausamento só acontece em
sincronização completa: numa incremental, "não apareceu" significa apenas "não
mudou".

### Leads de volta

Quando alguém preenche o formulário de um imóvel sincronizado, o lead entra numa
fila (`integration_outbox`) e é entregue ao SimobCRM por
`php spark integration:outbox`, de minuto em minuto.

Vai por fila, e não na hora, porque o servidor da imobiliária pode estar fora do
ar — e nesse caso ou o lead se perderia, ou o formulário do visitante travaria
esperando o timeout.

Se falhar, tenta de novo com espera crescente: 1 min, 5 min, 30 min, 2 h, 12 h.
Depois de 5 tentativas, o lead fica marcado como falho na listagem, com botão de
**reenviar**.

Se a imobiliária não tiver o módulo CRM contratado, o próprio Simob envia o
interesse por e-mail em vez de criar o registro.

### Comissão por negócio fechado

Quando um lead de imóvel sincronizado é marcado como **Concluído** com valor de
fechamento, e existe regra cadastrada, o sistema apura a comissão.

A apuração nasce como **Aguardando aprovação** — nada é cobrado sozinho. O
superadmin revisa em `/admin/comissoes` e aprova. O tenant acompanha o próprio
extrato em `/admin/minhas-comissoes`.

As regras ficam em `/admin/comissoes/regras`. A mais específica vence: regra da
conta para aquele tipo de negócio, depois regra da conta para qualquer tipo, e
por último a regra padrão da plataforma.

---

## Problemas comuns

**"Credencial recusada pela plataforma externa."**
Token errado, expirado, ou de outra imobiliária. Peça um novo em Principal ›
Sistema › Configurações › aba Integrações. Depois de trocar, teste a conexão de
novo — a integração é religada só depois de um teste bem-sucedido.

**"Conexão estabelecida, mas o Simob não devolveu nenhuma categoria."**
O token é válido, mas não há catálogo liberado para site, ou ele pertence a outra
imobiliária. Confira com a Flexpro se as categorias estão marcadas como
disponíveis no site.

**"A plataforma externa devolveu uma resposta em formato inesperado."**
Quase sempre a URL aponta para o site institucional da imobiliária em vez do
sistema Simob. Confira o endereço.

**"URL da plataforma externa inválida."**
O endereço não é http/https, ou aponta para a rede interna. Endereços internos são
bloqueados de propósito.

**O sync desligou sozinho.**
Acontece quando a credencial é recusada: insistir de 30 em 30 minutos com token
inválido só empilha erro e pode levar a bloqueio do lado do Simob. Corrija a
credencial, teste a conexão e reative.

**Imóveis pararam de entrar e o resumo diz "Limite de imóveis do plano atingido".**
O catálogo da imobiliária é maior que a cota do plano dela. Os imóveis que já
existem continuam sendo atualizados normalmente; só a criação de novos para. É
caso de upgrade de plano.

**Um imóvel específico não entrou.**
Veja o histórico de sincronizações — os erros por item aparecem lá. As causas
mais comuns são imóvel sem cidade ou bairro na origem, e imóvel sem preço em
nenhuma das finalidades.

**Editei um imóvel no Habitaweb e a alteração sumiu.**
Comportamento esperado em imóvel sincronizado: a origem é a fonte da verdade.
Altere no Simob.

---

## Comandos

```bash
# Sincroniza todas as integrações ativas (é o que o cron roda)
php spark integration:sync

# Um conector, ou uma conta específica
php spark integration:sync --provider=simob
php spark integration:sync --account=42

# Ignora o corte incremental e varre o catálogo inteiro.
# Útil depois de mudar mapeamentos, para reprocessar tudo.
php spark integration:sync --account=42 --full

# Entrega os leads pendentes
php spark integration:outbox --once
```

Cron de produção, na instância worker (ver `GUIA_ESCALABILIDADE_PRODUCAO.md` §3.4):

```cron
*/30 * * * * cd /var/www/habitaweb && php spark integration:sync >> writable/logs/integration-sync.log 2>&1
*    * * * * cd /var/www/habitaweb && php spark integration:outbox --max-time=55
```

---

## Para desenvolvedores

A arquitetura da lib está descrita no `CLAUDE.md`, seção *Integrations with
external platforms*. O resumo do que muda ao escrever um conector novo:

1. Uma classe implementando `IntegrationProviderInterface` (ou estendendo
   `AbstractProvider`) em `app/Libraries/Integrations/<Nome>/`.
2. Uma linha em `integration_providers`, com `class_name` e o `config_schema`
   que o painel deve renderizar.

Nada de controller, rota ou view precisa mudar.

Testes: `tests/unit/Integrations/` (conector e mappers, contra fixtures reais da
coleção Postman em `tests/_support/fixtures/simob/`) e
`tests/Feature/Integrations/` (painel, sync, outbox e comissão, com conector
dublê). Nenhum deles toca a rede.
