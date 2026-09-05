<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\PaymentService;

class CheckoutController extends BaseController
{
    protected $paymentService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
    }

    /**
     * List all plans
     */
    public function index()
    {
        $plans = model('App\Models\PlanModel')->comercializaveis();

        return view('web/checkout/plans', [
            'plans'      => $plans,
            // Preço de lead é único, igual em todo plano (ver docblock da
            // Fase 3) — mostrado uma vez na página, não por card.
            'leadPrices' => model('App\Models\LeadChargeRuleModel')->platformDefaults(),
            // Rampa (D1): resumo genérico das faixas vigentes, só pro ciclo
            // mensal (P6) — o mesmo texto vale pra qualquer plano.
            'rampBands'  => model('App\Models\PlanLaunchRampModel')->vigentes(),
        ]);
    }

    /**
     * Step 1: Confirm Plan & Choose Payment Method
     */
    public function plan($planId)
    {
        if (!auth()->loggedIn()) {
            return redirect()->to('login')->with('message', 'Faça login para continuar a assinatura.');
        }

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);

        if (!$plan) {
            return redirect()->back()->with('error', 'Plano não encontrado.');
        }

        $user = auth()->user();
        $account = null;
        if ($user->account_id) {
            $account = model('App\Models\AccountModel')->find($user->account_id);
        }

        // Prévia da rampa (D1) só faz sentido pro ciclo mensal (P6) — o
        // anual nunca entra nela. rampPreview fica null nos outros casos, e
        // a view mostra o preço cheio normalmente.
        $rampPreview = $this->rampPreviewFor($plan);

        return view('web/checkout/plan', [
            'plan' => $plan,
            'user' => $user,
            'account' => $account,
            'rampPreview' => $rampPreview,
        ]);
    }

    /**
     * Prévia de "R$ 0,00 hoje — R$ X a partir de DD/MM" pra tela de
     * confirmação do checkout, sobre uma assinatura provisória (o cadastro
     * ainda não existe). Mesma conta de `process()`, só que sem persistir
     * nada — é só o que a tela mostra ANTES do tenant confirmar.
     *
     * @return array{amount_today: float, next_amount: float, next_date: string}|null
     */
    private function rampPreviewFor($plan): ?array
    {
        $rampService   = new \App\Services\LaunchRampService($this->paymentService);
        $rampStartedAt = $rampService->enrollmentDateForNewSignup('MONTHLY');

        if ($rampStartedAt === null) {
            return null;
        }

        $rampSubscription = new \App\Entities\Subscription(['ramp_started_at' => $rampStartedAt]);
        $proxima          = $rampService->nextTransition($rampSubscription);

        if ($proxima === null) {
            return null;
        }

        $baseCiclo = $this->paymentService->getPlanAmountForBillingCycle($plan, 'MONTHLY');

        return [
            'amount_today' => $rampService->amountFor($plan, 'MONTHLY', $rampSubscription),
            'next_amount'  => round($baseCiclo * $proxima['to_percent'] / 100, 2),
            'next_date'    => $proxima['date'],
        ];
    }

    /**
     * Step 2: Process Payment
     */
    /**
     * Step 2: Process Payment (Tokenization Flow)
     */
    public function process()
    {
        if (!auth()->loggedIn()) {
            return redirect()->to('login');
        }

        $rules = [
            'plan_id' => 'required|integer',
            'billing_type' => 'required|in_list[PIX,BOLETO,CREDIT_CARD]',
            'billing_cycle' => 'required|in_list[MONTHLY,QUARTERLY,SEMIANNUALLY,YEARLY]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $planId = $this->request->getPost('plan_id');
        $billingType = $this->request->getPost('billing_type');
        $billingCycle = $this->request->getPost('billing_cycle');
        $user = auth()->user();
        $couponCode = $this->request->getPost('coupon_code');

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);

        if (! $plan || ! $plan->ativo) {
            return redirect()->back()->withInput()->with('error', 'Plano indisponível.');
        }

        // O in_list acima só valida que o ciclo existe, não que ESTE plano o
        // vende. As colunas de preço por ciclo têm DEFAULT 0.00 e o PlanSeeder
        // preenche apenas mensal e anual — sem esta checagem, um POST com
        // billing_cycle=QUARTERLY assinava 3 meses por R$ 0,00.
        if (! (new \App\Services\PaymentService())->planSupportsBillingCycle($plan, $billingCycle)) {
            return redirect()->back()->withInput()->with(
                'error',
                'Este plano não está disponível na periodicidade escolhida.'
            );
        }

        $gracePeriodDays = (int) $plan->carencia_dias;

        // A rampa decide o valor ANTES de qualquer chamada ao gateway (D1):
        // Asaas não aceita assinatura de R$0, e criar uma "de mentirinha"
        // (R$0,01) mentiria na fatura do cliente. Só MONTHLY entra (P6) —
        // enrollmentDateForNewSignup() já filtra isso.
        $rampService      = new \App\Services\LaunchRampService($this->paymentService);
        $rampStartedAt    = $rampService->enrollmentDateForNewSignup($billingCycle);
        $rampSubscription = new \App\Entities\Subscription(['ramp_started_at' => $rampStartedAt]);
        $effectiveAmount  = $rampService->amountFor($plan, $billingCycle, $rampSubscription);

        if ($effectiveAmount <= 0) {
            $this->paymentService->createFreeLocalSubscription($user->account_id, $plan, $billingCycle, $rampStartedAt);

            $mensagem = "Seu plano {$plan->nome} começa em R$ 0,00 hoje";
            $proxima  = $rampService->nextTransition($rampSubscription);

            if ($proxima !== null) {
                $baseCiclo    = $this->paymentService->getPlanAmountForBillingCycle($plan, $billingCycle);
                $valorProximo = round($baseCiclo * $proxima['to_percent'] / 100, 2);
                $mensagem .= ' — R$ ' . number_format($valorProximo, 2, ',', '.')
                    . ' a partir de ' . date('d/m/Y', strtotime($proxima['date']));
            }

            return redirect()->to('admin/subscription')->with('success', $mensagem);
        }

        try {
            log_message('debug', '[Checkout] Processando pagamento para conta ' . $user->account_id);
            if ($billingType === 'CREDIT_CARD') {
                log_message('debug', '[Checkout] Iniciando fluxo de Tokenização para Cartão.');
                // Tokenization Flow: Capture token for future self-managed recurrence
                $result = $this->paymentService->initiateTokenizationPayment(
                    $user->account_id,
                    $planId,
                    $billingType,
                    $billingCycle,
                    $gracePeriodDays,
                    $couponCode,
                    $rampStartedAt
                );
            } else {
                log_message('debug', '[Checkout] Iniciando Assinatura Nativa (PIX/Boleto).');
                // Native Subscription: Asaas manages recurrence for boleto/pix
                $result = $this->paymentService->initializeSubscription(
                    $user->account_id,
                    $planId,
                    $billingType,
                    [], // No card data yet for redirect flow
                    $couponCode,
                    $billingCycle,
                    $gracePeriodDays,
                    $rampStartedAt
                );
            }

            log_message('debug', '[Checkout] Redirecionando. Tipo: ' . $billingType);

            // Conditional Redirection based on Payment Method
            if (in_array($billingType, ['PIX', 'BOLETO'])) {
                return redirect()->to('admin/subscription')->with('success', 'Fatura gerada! Efetue o pagamento para liberar o acesso.');
            }

            // For Credit Card (Tokenization URL) or default
            return redirect()->to('checkout/success')->with('subscription', $result);

        } catch (\Exception $e) {
            log_message('error', '[Checkout] Erro no processamento: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Erro ao processar: ' . $e->getMessage());
        }
    }

    /**
     * Step 3: Success Page
     */
    public function success()
    {
        if (!session()->has('subscription')) {
            return redirect()->to('/');
        }
        
        $sessionData = session('subscription');
        
        // Verificar se os dados existem na sessão
        if (!$sessionData) {
            return redirect()->to('/')->with('error', 'Dados da assinatura não encontrados.');
        }
        
        // $sessionData['subscription'] contains Asaas response
        // $sessionData['local_id'] contains local ID

        return view('web/checkout/success', [
            'subscription' => $sessionData['subscription'] ?? [],
            'local_id' => $sessionData['local_id'] ?? null
        ]);
    }

    /**
     * AJAX: Validate Coupon
     */
    public function validateCoupon()
    {
        $code = $this->request->getGet('code');
        $planId = $this->request->getGet('plan_id');
        $cycle = $this->request->getGet('billing_cycle') ?? 'MONTHLY';
        
        if (empty($code) || empty($planId)) {
             return $this->response->setJSON(['valid' => false, 'message' => 'Dados incompletos.']);
        }

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);
        
        if (!$plan) {
             return $this->response->setJSON(['valid' => false, 'message' => 'Plano inválido.']);
        }
        
        $basePrice = (float)$plan->preco_mensal;
        switch($cycle) {
            case 'QUARTERLY': $basePrice = (float)$plan->preco_trimestral; break;
            case 'SEMIANNUALLY': $basePrice = (float)$plan->preco_semestral; break;
            case 'YEARLY': $basePrice = (float)$plan->preco_anual; break;
        }

        $accountId = null;
        if (auth()->loggedIn() && auth()->user()->account_id) {
            $accountId = auth()->user()->account_id;
        }

        $result = $this->paymentService->validateCoupon($code, $basePrice, $accountId);
        
        return $this->response->setJSON($result);
    }
}
