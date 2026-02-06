<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class LeadController extends BaseController
{
    public function store()
    {
        $rules = [
            'property_id'    => 'required|is_not_unique[properties.id]',
            'nome_visitante' => 'required|min_length[3]',
            'email_visitante' => 'required|valid_email',
            'telefone_visitante' => 'required|min_length[8]',
        ];

        if (!$this->validate($rules)) {
            return $this->response->setJSON([
                'success' => false,
                'errors'  => $this->validator->getErrors()
            ])->setStatusCode(400);
        }

        $service = service('leadService');
        
        $data = [
            'property_id' => $this->request->getPost('property_id'),
            'nome_visitante' => $this->request->getPost('nome_visitante'),
            'email_visitante' => $this->request->getPost('email_visitante'),
            'telefone_visitante' => $this->request->getPost('telefone_visitante'),
            'mensagem' => $this->request->getPost('mensagem'),
            'origem'   => 'SITE',
            'tipo_lead' => 'MSG'
        ];

        $result = $service->trySaveLead($data);

        if ($result['success']) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Recebemos seu contato! Logo retornaremos.'
            ]);
        }
        return $this->response->setJSON($result)->setStatusCode(500);
    }

    public function registerEvent()
    {
        $propertyId = $this->request->getPost('property_id');
        $evento     = $this->request->getPost('evento') ?? 'whatsapp_click';
        $payload    = $this->request->getPost('payload');
        
        // Dados do visitante vindos do modal (opcional)
        $nome       = $this->request->getPost('nome_visitante');
        $email      = $this->request->getPost('email_visitante');
        $telefone   = $this->request->getPost('telefone_visitante');

        if (!$propertyId) {
            return $this->response->setJSON(['success' => false, 'message' => 'Property ID missing'])->setStatusCode(400);
        }

        $service = service('leadService');

        // Cria um lead com os dados reais ou placeholder se não informados
        $data = [
            'property_id'    => $propertyId,
            'nome_visitante' => $nome ?: 'Visitante WhatsApp',
            'email_visitante' => $email,
            'telefone_visitante' => $telefone,
            'origem'         => 'SITE',
            'tipo_lead'      => 'WHATSAPP',
            'status'         => 'NOVO'
        ];

        $result = $service->trySaveLead($data);

        if ($result['success']) {
            $lead = $result['data'];
            $service->registerEvent($lead->id, $evento, json_decode($payload, true));
            
            return $this->response->setJSON([
                'success' => true, 
                'lead_id' => $lead->id
            ]);
        }

        return $this->response->setJSON($result)->setStatusCode(500);
    }
}
