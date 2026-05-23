<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class LeadController extends BaseController
{
    private function jsonPayload(array $payload): array
    {
        $payload['csrf_token'] = csrf_token();
        $payload['csrf_hash'] = csrf_hash();

        return $payload;
    }

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
                'message' => 'Revise os dados do formulário.',
                'errors'  => $this->validator->getErrors(),
                'csrf_token' => csrf_token(),
                'csrf_hash' => csrf_hash(),
            ])->setStatusCode(422);
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
            return $this->response->setJSON($this->jsonPayload([
                'success' => true,
                'message' => 'Recebemos seu contato! Logo retornaremos.',
                'lead_id' => $result['data']->id ?? null,
            ]));
        }

        return $this->response->setJSON($this->jsonPayload([
            'success' => false,
            'message' => $result['message'] ?? 'Erro ao registrar lead.',
            'errors'  => $result['errors'] ?? [],
        ]))->setStatusCode(422);
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
            return $this->response->setJSON($this->jsonPayload([
                'success' => false,
                'message' => 'Imóvel não informado.',
            ]))->setStatusCode(400);
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
            $eventPayload = json_decode((string) $payload, true);
            $service->registerEvent((int) $lead->id, $evento, is_array($eventPayload) ? $eventPayload : null);
            
            return $this->response->setJSON($this->jsonPayload([
                'success' => true, 
                'lead_id' => $lead->id
            ]));
        }

        return $this->response->setJSON($this->jsonPayload([
            'success' => false,
            'message' => $result['message'] ?? 'Erro ao registrar evento do lead.',
            'errors'  => $result['errors'] ?? [],
        ]))->setStatusCode(422);
    }
}
