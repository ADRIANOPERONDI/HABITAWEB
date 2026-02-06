Fluxo de Recorrência e Tokenização Asaas
Este documento detalha como o sistema Soulclinic gerencia a tokenização de cartões de crédito e a geração de cobranças recorrentes utilizando o gateway de pagamento Asaas.

1. Seleção da Forma de Pagamento
No checkout (
contratar_plano.php
), o usuário seleciona a opção Cartão de Crédito (CREDIT_CARD).

Importante: O sistema não coleta os dados do cartão localmente. Isso garante segurança e simplifica a conformidade com normas de segurança (PCI DSS).
2. Início do Processo (Backend)
Quando o usuário clica em contratar, o ConfiguracoesContasController::processarContratacao realiza os seguintes passos:

Criação do Cliente: Registra o cliente no Asaas caso ainda não exista.
Assinatura (Subscription): Cria uma assinatura no Asaas vinculada ao plano escolhido.
Primeira Fatura: Gera uma fatura proporcional no banco de dados local.
Solicitação de Tokenização: Cria uma cobrança (Pagamento) no Asaas para essa fatura proporcional, enviando o parâmetro "tokenizeCreditCard" => true.
3. Captura do Token (Webhook)
O usuário recebe um link para pagamento seguro do Asaas. Ao realizar o primeiro pagamento e inserir os dados do cartão no ambiente do Asaas:

O Asaas processa o pagamento e gera um creditCardToken.
O Asaas envia um Webhook de confirmação de pagamento (PAYMENT_RECEIVED) para o sistema Soulclinic.
O 
AsaasConciliacaoService
 intercepta esse webhook, extrai o creditCardToken e o armazena na tabela contratacoes (coluna gateway_card_token).
4. Execução da Recorrência
A partir do segundo mês, o processo de Fechamento de Faturas (
FechamentoFaturaService
) automatiza a cobrança:

O sistema identifica que a contratação possui um gateway_card_token.
Ao criar a nova cobrança no Asaas, o sistema envia este token no campo creditCardToken.
O Asaas realiza a cobrança automática no cartão salvo, sem que o cliente precise intervir ou digitar os dados novamente.
Resumo dos Arquivos Chave
ConfiguracoesContasController.php
: Orquestra o início da contratação.
AsaasService.php
: Interface de comunicação com a API do Asaas.
AsaasConciliacaoService.php
: Captura e salva o token do cartão via webhook.
FechamentoFaturaService.php
: Utiliza o token salvo para disparar as cobranças automáticas futuras.