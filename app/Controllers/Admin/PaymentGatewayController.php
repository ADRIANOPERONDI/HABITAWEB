<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class PaymentGatewayController extends BaseController
{
    protected $gatewayModel;
    protected $configModel;

    public function __construct()
    {
        $this->gatewayModel = model('App\Models\PaymentGatewayModel');
        $this->configModel = model('App\Models\PaymentGatewayConfigModel');
    }

    /**
     * List all payment gateways
     */
    public function index()
    {
        $gateways = $this->gatewayModel->findAll();
        
        return view('Admin/payment_gateways/index', [
            'gateways' => $gateways
        ]);
    }

    /**
     * Configure a specific gateway
     */
    public function configure($id)
    {
        $gateway = $this->gatewayModel->find($id);
        
        if (!$gateway) {
            return redirect()->to('/admin/payment-gateways')->with('error', 'Gateway não encontrado.');
        }

        $configs = $this->configModel->where('gateway_id', $id)
                                    ->orderBy('display_order', 'ASC')
                                    ->findAll();

        // Decrypt values for display
        foreach ($configs as &$config) {
            if ($config->is_sensitive && !empty($config->config_value)) {
                try {
                    $encrypter = \Config\Services::encrypter();
                    $config->config_value = $encrypter->decrypt(base64_decode($config->config_value));
                } catch (\Exception $e) {
                    $config->config_value = ''; // Failed to decrypt or invalid
                }
            }
        }

        return view('Admin/payment_gateways/configure', [
            'gateway' => $gateway,
            'configs' => $configs
        ]);
    }

    /**
     * Update configuration for a specific gateway
     */
    public function update($id)
    {
        $gateway = $this->gatewayModel->find($id);
        
        if (!$gateway) {
            return redirect()->to('/admin/payment-gateways')->with('error', 'Gateway não encontrado.');
        }

        $postData = $this->request->getPost();
        
        foreach ($postData as $key => $value) {
            // Ignore CSRF token and other non-config fields if any
            if ($key === 'csrf_test_name') continue;

            $config = $this->configModel->where([
                'gateway_id' => $id,
                'config_key' => $key
            ])->first();

            if ($config) {
                $this->configModel->saveConfig($id, $key, $value, $config->is_sensitive);
            }
        }

        // Test configuration
        try {
            $className = $gateway->class_name;
            
            if (class_exists($className)) {
                $instance = new $className();
                
                // Load updated configs
                $updatedConfigs = $this->configModel->getGatewayConfig($id, true);
                $instance->configure($updatedConfigs);
                
                if ($instance->validateConfig()) {
                    session()->setFlashdata('message', 'Configuração salva e validada com sucesso! ✅');
                } else {
                    session()->setFlashdata('warning', 'Configuração salva, mas a validação falhou. Verifique as credenciais. ⚠️');
                }
            } else {
                 session()->setFlashdata('warning', 'Configuração salva. (Classe do gateway não encontrada para validação)');
            }

        } catch (\Exception $e) {
            session()->setFlashdata('error', 'Erro ao validar gateway: ' . $e->getMessage());
        }

        return redirect()->to("/admin/payment-gateways/configure/{$id}");
    }

    /**
     * Toggle active status
     */
    public function toggle($id)
    {
        // Check if AJAX
        if (!$this->request->isAJAX()) {
             // return redirect...
        }

        $gateway = $this->gatewayModel->find($id);
        if (!$gateway) {
            return $this->response->setJSON(['success' => false, 'message' => 'Gateway não encontrado']);
        }
        
        // Cannot deactivate primary gateway easily? Or logic handles it.
        // If primary is deactivated, no payments work.
        
        $newState = !$gateway->is_active;
        $this->gatewayModel->update($id, ['is_active' => $newState]);
        
        return $this->response->setJSON(['success' => true, 'new_state' => $newState]);
    }

    /**
     * Set as primary gateway
     */
    public function setPrimary($id)
    {
        $gateway = $this->gatewayModel->find($id);
        if (!$gateway) {
            return redirect()->back()->with('error', 'Gateway não encontrado');
        }
        
        if (!$gateway->is_active) {
            // Force activate
             $this->gatewayModel->update($id, ['is_active' => true]);
        }
        
        $this->gatewayModel->setPrimary($id);
        
        return redirect()->back()->with('message', "{$gateway->name} definido como gateway principal! 🚀");
    }

    /**
     * Sync and fix duplicate primary/active statuses
     */
    public function sync()
    {
        $db = \Config\Database::connect();
        
        // Remove todos os primários
        $db->query("UPDATE payment_gateways SET is_primary = false");
        
        // Define Asaas como primário se existir, senão o primeiro encontrado
        $asaas = $this->gatewayModel->where('code', 'asaas')->first();
        if ($asaas) {
            $this->gatewayModel->setPrimary($asaas->id);
        } else {
            $first = $this->gatewayModel->first();
            if ($first) $this->gatewayModel->setPrimary($first->id);
        }

        // Limpa scripts temporários se existirem
        @unlink(ROOTPATH . 'check_gateways.php');
        @unlink(ROOTPATH . 'reset_gateways.php');

        return redirect()->to('/admin/payment-gateways')->with('message', 'Status de gateways sincronizados com sucesso! ✅');
    }
}
