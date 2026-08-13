<?php

namespace App\Services;

use App\Models\PromotionModel;
use App\Models\PromotionPackageModel;
use CodeIgniter\Config\Factories;

class PromotionService
{
    protected PromotionModel $promotionModel;
    protected PromotionPackageModel $packageModel;

    public function __construct()
    {
        $this->promotionModel = Factories::models(PromotionModel::class);
        $this->packageModel   = Factories::models(PromotionPackageModel::class);
    }

    /** Pacotes que representam exposição de imóvel por prazo. */
    public const TIPO_TURBO = 'TURBO_IMOVEL';

    /**
     * Lista os pacotes compráveis de um tipo.
     *
     * O default é TURBO_IMOVEL porque `promotion_packages` também guarda os
     * pacotes de LEAD (LEAD_COMPRA, LEAD_ALUGUEL), que são preço por unidade e
     * têm duracao_dias = 0. Sem este filtro a tela de turbinar os oferecia como
     * se fossem exposição — e comprá-los criava promoção com data_fim igual à
     * data_inicio, ou seja, destaque já nascido expirado.
     *
     * O filtro por duracao_dias > 0 é cinto e suspensório: pacote de exposição
     * sem prazo não é comprável, qualquer que seja o tipo.
     */
    public function listPackages(string $tipo = self::TIPO_TURBO)
    {
        return $this->packageModel
            ->where('tipo_promocao', $tipo)
            ->where('duracao_dias >', 0)
            ->orderBy('preco', 'ASC')
            ->findAll();
    }

    /**
     * Prepara a aplicação de um pacote, gerando a cobrança no gateway.
     */
    public function applyPackage(int $propertyId, string $packageKey): array
    {
        $package = $this->packageModel->where('chave', $packageKey)->first();

        if (!$package) {
            return ['success' => false, 'message' => 'Pacote não encontrado.'];
        }

        // Barra no serviço, não só na tela: um POST direto com package_key de um
        // pacote de LEAD geraria cobrança e, na confirmação, um destaque expirado.
        if ($package->tipo_promocao !== self::TIPO_TURBO || (int) $package->duracao_dias <= 0) {
            return ['success' => false, 'message' => 'Este pacote não pode ser aplicado a um imóvel.'];
        }

        $propertyModel = new \App\Models\PropertyModel();
        $property = $propertyModel->find($propertyId);

        if (!$property) {
            return ['success' => false, 'message' => 'Imóvel não encontrado.'];
        }

        $accountModel = new \App\Models\AccountModel();
        $account = $accountModel->find($property->account_id);

        if (!$account) {
            return ['success' => false, 'message' => 'Conta do anunciante não encontrada.'];
        }

        if (empty($account->documento)) {
            return ['success' => false, 'message' => 'Sua conta não possui CPF ou CNPJ cadastrado. Complete seu perfil para poder turbinar imóveis.'];
        }

        if (empty($account->nome) || empty($account->email)) {
            return ['success' => false, 'message' => 'Sua conta possui dados incompletos (Nome ou E-mail). Complete seu perfil para prosseguir.'];
        }

        // 1. Preparar dados para o Asaas
        $asaasService = new \App\Services\AsaasService();
        
        try {
            // Garantir que o cliente existe no Asaas
            $customer = $asaasService->createCustomer([
                'name'    => $account->nome,
                'email'   => $account->email,
                'cpfCnpj' => preg_replace('/\D/', '', $account->documento ?? ''),
            ]);

            // 2. Criar Transação de Pagamento
            $paymentData = [
                'customer'    => $customer['id'],
                'billingType' => 'UNDEFINED', // Deixa o usuário escolher no checkout do Asaas (Pix, Boleto, Cartão)
                'value'       => $package->preco,
                'dueDate'     => date('Y-m-d', strtotime('+3 days')),
                'description' => "Turbinar Imóvel: " . $property->titulo . " (" . $package->nome . ")",
                'externalReference' => "PROMO_{$propertyId}_" . time(),
            ];

            $payment = $asaasService->createPayment($paymentData);

            // 3. Registrar Transação no Banco Local
            $transactionModel = new \App\Models\PaymentTransactionModel();
            $transactionModel->insert([
                'account_id' => $account->id,
                'gateway'    => 'asaas',
                'gateway_transaction_id' => $payment['id'],
                'amount'     => $package->preco,
                'currency'   => 'BRL',
                'status'     => 'PENDING',
                'payment_method' => 'UNDEFINED',
                'type'       => 'TURBO',
                'description'   => "Turbinar Imóvel: " . $property->titulo . " (" . $package->nome . ")",
                'invoice_url'   => $payment['invoiceUrl'],
                'metadata'   => json_encode([
                    'property_id'   => $propertyId,
                    'promo_key'     => $packageKey,
                    'package_key'   => $packageKey,
                    'package_name'  => $package->nome,
                    'invoice_url'   => $payment['invoiceUrl']
                ])
            ]);

            return [
                'success'     => true,
                'message'     => 'Pagamento gerado com sucesso.',
                'invoice_url' => $payment['invoiceUrl'],
                'payment_id'  => $payment['id']
            ];

        } catch (\Exception $e) {
            log_message('error', 'Erro ao gerar pagamento Turbo: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao processar pagamento: ' . $e->getMessage()];
        }
    }

    /**
     * Remove promoções expiradas (Rotina de Cron Job)
     */
    public function deactivateExpired()
    {
        $expired = $this->promotionModel->where('ativo', true)
                                        ->where('data_fim <', date('Y-m-d H:i:s'))
                                        ->findAll();

        if (empty($expired)) return;

        $db = \Config\Database::connect();
        $propModel = new \App\Models\PropertyModel();

        foreach ($expired as $promo) {
            $db->transStart();
            
            $this->promotionModel->update($promo->id, ['ativo' => false]);
            
            // Verifica se tem outra promoção ativa antes de zerar?
            // Simplificação: Zera o destaque do imóvel.
            // Idealmente buscaria a próxima promoção ativa.
            $activePromo = $this->promotionModel->where('property_id', $promo->property_id)
                                                ->where('ativo', true)
                                                ->where('id !=', $promo->id)
                                                ->where('data_fim >', date('Y-m-d H:i:s'))
                                                ->orderBy('data_fim', 'DESC')
                                                ->first();
            
            if ($activePromo) {
                 // Mantém o nível da outra promo (simplificado)
                 // TODO: Recalcular nível correto
            } else {
                 $propModel->update($promo->property_id, [
                     'highlight_level' => 0,
                     'highlight_expires_at' => null
                 ]);
            }
            
            $db->transComplete();
        }
    }

    /**
     * Ativa uma promoção após a confirmação do pagamento.
     */
    public function activatePaidPromotion(int $propertyId, string $packageKey): bool
    {
        $package = $this->packageModel->where('chave', $packageKey)->first();
        if (!$package) return false;

        // Calcula datas
        $startDate = date('Y-m-d H:i:s');
        $endDate   = date('Y-m-d H:i:s', strtotime("+{$package->duracao_dias} days"));

        // Define Nível de Destaque.
        //
        // TURBO_IMOVEL é o único tipo realmente vendido hoje (TURBO_7_DIAS) e não
        // tinha braço próprio: caía no `default => 1` por coincidência. Tipo
        // desconhecido agora falha alto em vez de virar destaque de graça — se um
        // pacote novo for cadastrado sem mapeamento, queremos saber pelo log, não
        // descobrir meses depois que ele concedia exposição sem regra.
        $level = match ($package->tipo_promocao) {
            self::TIPO_TURBO => 1,
            'DESTAQUE'       => 1,
            'SUPER_DESTAQUE' => 2,
            'VITRINE'        => 3,
            default          => null,
        };

        if ($level === null) {
            log_message('error', sprintf(
                '[Promocao] tipo_promocao sem nivel mapeado: "%s" (pacote %s, imovel %d). Ativacao abortada.',
                $package->tipo_promocao,
                $package->chave,
                $propertyId
            ));

            return false;
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // 1. Criar ou Atualizar entrada em 'promotions'
        $data = [
            'property_id'   => $propertyId,
            'tipo_promocao' => $package->tipo_promocao,
            'data_inicio'   => $startDate,
            'data_fim'      => $endDate,
            'ativo'         => true
        ];
        $this->promotionModel->save($data);

        // 2. Atualizar Imóvel (Denormalização para performance na busca)
        $propModel = new \App\Models\PropertyModel();
        $propModel->update($propertyId, [
            'highlight_level'      => $level,
            'highlight_expires_at' => $endDate
        ]);

        $db->transComplete();

        return $db->transStatus();
    }
}
