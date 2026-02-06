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
        $planModel = model('App\Models\PlanModel');
        $plans = $planModel->where('ativo', true)->findAll();

        return view('web/checkout/plans', [
            'plans' => $plans
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

        return view('web/checkout/plan', [
            'plan' => $plan,
            'user' => $user,
            'account' => $account
        ]);
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
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $planId = $this->request->getPost('plan_id');
        $billingType = $this->request->getPost('billing_type');
        $user = auth()->user();
        $couponCode = $this->request->getPost('coupon_code');

        try {
            log_message('debug', '[Checkout] Processando pagamento para conta ' . $user->account_id);
            if ($billingType === 'CREDIT_CARD') {
                log_message('debug', '[Checkout] Iniciando fluxo de Tokenização para Cartão.');
                // Tokenization Flow: Capture token for future self-managed recurrence
                $result = $this->paymentService->initiateTokenizationPayment(
                    $user->account_id,
                    $planId,
                    $billingType,
                    $couponCode
                );
            } else {
                log_message('debug', '[Checkout] Iniciando Assinatura Nativa (PIX/Boleto).');
                // Native Subscription: Asaas manages recurrence for boleto/pix
                $result = $this->paymentService->initializeSubscription(
                    $user->account_id,
                    $planId,
                    $billingType,
                    [], // No card data yet for redirect flow
                    $couponCode
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
        
        if (empty($code) || empty($planId)) {
             return $this->response->setJSON(['valid' => false, 'message' => 'Dados incompletos.']);
        }

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);
        
        if (!$plan) {
             return $this->response->setJSON(['valid' => false, 'message' => 'Plano inválido.']);
        }

        $result = $this->paymentService->validateCoupon($code, (float)$plan->preco_mensal);
        
        return $this->response->setJSON($result);
    }
}
