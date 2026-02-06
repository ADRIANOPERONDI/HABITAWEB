<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PropertyReportModel;
use App\Models\PropertyModel;
use App\Services\CurationService;

class CurationController extends BaseController
{
    protected $reportModel;
    protected $propertyModel;
    protected $curationService;

    public function __construct()
    {
        $this->reportModel = new PropertyReportModel();
        $this->propertyModel = new PropertyModel();
        $this->curationService = new CurationService();
    }

    public function index()
    {
        $status = $this->request->getGet('status') ?? 'PENDING';
        
        $data = [
            'title'   => 'Curadoria de Imóveis',
            'reports' => $this->reportModel
                ->select('property_reports.*, properties.titulo, properties.status as prop_status, auth_identities.secret as reporter_email')
                ->join('properties', 'properties.id = property_reports.property_id')
                ->join('auth_identities', 'auth_identities.user_id = property_reports.user_id AND auth_identities.type = \'email_password\'', 'left')
                ->where('property_reports.status', $status)
                ->orderBy('created_at', 'DESC')
                ->paginate(20),
            'pager'   => $this->reportModel->pager,
            'filter_status' => $status,
            'flagged_properties' => $this->propertyModel
                ->where('moderation_status', 'PENDING_REVIEW')
                ->findAll(20) // Limit for queue overview
        ];

        return view('Admin/curation/index', $data);
    }

    public function resolveReport($id)
    {
        $action = $this->request->getPost('action'); // DISMISS, PAUSE_PROPERTY, BAN_USER
        $notes  = $this->request->getPost('notes');

        $report = $this->reportModel->find($id);
        if (!$report) {
            return $this->response->setJSON(['success' => false, 'message' => 'Denúncia não encontrada.']);
        }

        $property = $this->propertyModel->find($report['property_id']); // Reports are array based on Model setup, assuming array return.

        if ($action === 'PAUSE_PROPERTY') {
            $property->status = 'PAUSED';
            $property->auto_paused = true;
            $property->auto_paused_reason = 'Denúncia acatada: ' . $report['type'];
            $this->propertyModel->save($property);
            
            $statusReport = 'RESOLVED';
        } elseif ($action === 'DISMISS') {
            $statusReport = 'REJECTED';
        } else {
            $statusReport = 'RESOLVED';
        }

        $this->reportModel->update($id, [
            'status' => $statusReport,
            'resolution_notes' => $notes
        ]);

        return $this->response->setJSON(['success' => true, 'message' => 'Denúncia processada com sucesso.']);
    }

    public function approveProperty($id)
    {
        $property = $this->propertyModel->find($id);
        if ($property) {
            $property->moderation_status = 'APPROVED';
            // Clear warnings? Maybe keep history.
            $property->quality_warnings = []; 
            $this->propertyModel->save($property);
            return $this->response->setJSON(['success' => true, 'message' => 'Imóvel aprovado com sucesso!']);
        }
        return $this->response->setJSON(['success' => false, 'message' => 'Erro ao aprovar imóvel.']);
    }
}
