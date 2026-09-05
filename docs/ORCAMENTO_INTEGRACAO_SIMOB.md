# Proposta comercial — Integração Habitaweb ⇄ Simob

**Cliente:** _________________________
**Data:** 12/08/2026
**Validade da proposta:** 30 dias

---

## O problema que isto resolve

Hoje a imobiliária mantém o catálogo em dois lugares. Cada imóvel novo precisa ser
cadastrado duas vezes, cada mudança de preço precisa ser repetida, e cada contato
que chega pelo portal precisa ser copiado à mão para o sistema de gestão.

Isso custa tempo, gera divergência entre os dois catálogos (imóvel já alugado
continuando no ar) e faz o corretor trabalhar olhando duas telas.

## O que será entregue

Uma ligação automática entre o **Simob**, que a imobiliária já usa, e o
**Habitaweb**, funcionando nas duas direções:

**Do Simob para o Habitaweb — o catálogo**

- Os imóveis cadastrados no Simob passam a aparecer no Habitaweb sozinhos, com
  descrição, endereço, valores, características e fotos.
- Alterações feitas no Simob (preço, descrição, fotos) chegam ao Habitaweb
  automaticamente, sem ninguém repetir o trabalho.
- Imóvel vendido, alugado ou retirado do site no Simob sai do ar no Habitaweb.
- A atualização roda sozinha a cada 30 minutos, e há um botão de
  "Sincronizar agora" para quando houver pressa.

**Do Habitaweb para o Simob — os leads**

- Todo contato recebido no portal em um imóvel sincronizado é enviado para o
  Simob e aparece junto dos demais atendimentos da imobiliária.
- O corretor continua trabalhando dentro do Simob, sem precisar acompanhar dois
  sistemas.
- Se o Simob estiver indisponível no momento, o contato fica guardado e é
  entregue assim que voltar. Nenhum lead se perde.

**Painel de controle próprio**

- Tela dentro do Habitaweb onde a imobiliária informa os dados de acesso ao
  Simob e confere, com um clique, se a ligação está funcionando.
- Tela de correspondência entre os dois sistemas: qual categoria do Simob
  equivale a qual tipo de imóvel no Habitaweb, e qual característica alimenta
  qual campo. O sistema preenche uma sugestão automática e a imobiliária apenas
  confirma.
- Histórico de todas as atualizações, com o que entrou, o que mudou e o que deu
  errado — para que qualquer dúvida futura tenha resposta.
- Cada imobiliária enxerga e configura apenas os próprios dados.

**Controle de comissão por negócio fechado**

- Quando um contato vindo do portal vira negócio fechado, o sistema calcula a
  comissão conforme a regra combinada (percentual ou valor fixo, com mínimo e
  máximo, diferenciando venda de locação).
- Nada é cobrado automaticamente: o valor fica registrado aguardando conferência
  e aprovação.
- A imobiliária acompanha o próprio extrato, para não haver surpresa na fatura.

---

## Etapas e horas

| # | Etapa | Horas |
|---|---|---:|
| 1 | Preparação do ambiente e estrutura de dados | 6 |
| 2 | Motor de integração (base reaproveitável para conectar outros sistemas depois) | 8 |
| 3 | Conector do Simob: leitura do catálogo, tradução dos campos e das fotos | 14 |
| 4 | Painel de controle da imobiliária: acesso, teste de conexão e correspondências | 13 |
| 5 | Sincronização automática do catálogo, incluindo agendamento e proteções | 13 |
| 6 | Envio dos leads para o Simob, com reenvio automático em caso de falha | 9 |
| 7 | Controle de comissão por negócio fechado | 10 |
| 8 | Testes, documentação e ajustes finais | 7 |
| | **Total** | **80 h** |

## Investimento

| | |
|---|---|
| Horas | 80 |
| Valor da hora | R$ 100,00 |
| **Total** | **R$ 8.000,00** |

**Prazo:** 4 a 6 semanas a partir da liberação dos acessos.

**Forma de pagamento:** 30% na aprovação da proposta, 40% na entrega da
sincronização do catálogo funcionando (etapa 5) e 30% na entrega final.

**Garantia:** 30 dias após a entrega para correção de qualquer defeito no que foi
contratado, sem custo.

---

## O que está incluído

- Todo o desenvolvimento descrito acima.
- Testes automatizados cobrindo as regras críticas.
- Documentação técnica e manual de operação.
- Configuração e acompanhamento da primeira imobiliária ligada ao sistema.
- Treinamento de uso do painel (1 sessão remota de até 1 hora).

## O que não está incluído

Itens fora deste escopo, orçados à parte caso sejam necessários:

- **Publicar no Simob um imóvel criado no Habitaweb.** A interface do Simob não
  permite criação nem alteração de imóveis por sistemas externos — ela é apenas
  de leitura para o catálogo. O sentido imóveis→Habitaweb e leads→Simob é o que
  a plataforma de origem torna possível.
- Consumo dos dados de pessoas, contratos e boletos do Simob. Exigem uma segunda
  credencial fornecida pela Flexpro e não fazem parte deste projeto.
- Conectores para outras plataformas (Vista, Ingaia, Jetimob e afins). Como a
  base construída aqui é reaproveitável, cada conector adicional fica em torno de
  35 a 45 horas, e não nas 80 deste projeto.
- Ajustes de layout, novos relatórios ou funcionalidades no Habitaweb que não
  estejam listados acima.
- Emissão automática das cobranças de comissão pelo gateway de pagamento. O
  cálculo e o controle estão incluídos; a geração da fatura é integração
  financeira à parte.
- Hospedagem, licenças e custos do próprio Simob.

## Premissas

O prazo e o valor assumem que:

1. A imobiliária fornecerá o endereço do sistema e o token de integração do
   Simob no início do projeto (ambos estão no próprio Simob, em
   *Principal › Sistema › Configurações › aba Integrações*).
2. Haverá um ambiente do Simob disponível para testes durante o desenvolvimento.
3. A interface do Simob se comportará conforme a documentação oficial publicada
   pela Flexpro Sistemas.

Caso o item 1 ou 2 atrase, o prazo se desloca na mesma medida. Caso o item 3 não
se confirme e a plataforma responda de forma diferente do documentado, o esforço
adicional será apresentado e aprovado antes de qualquer execução.

## Manutenção mensal (opcional)

Recomendada, mas não obrigatória, e contratada à parte:

- Monitoramento das sincronizações e correção de falhas.
- Adequação a mudanças que a Flexpro fizer na interface do Simob.
- Suporte às dúvidas de uso.

**R$ 600,00 por mês.** Ligar uma imobiliária nova depois da entrega:
**R$ 400,00** por imobiliária, pois a correspondência entre categorias e
características é própria de cada uma e exige conferência.

---

_Proposta elaborada por_ **Cristian Dutra de Campos da Silva**
