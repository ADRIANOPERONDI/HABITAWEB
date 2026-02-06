<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PromotionPackageModel;
use CodeIgniter\Exceptions\PageNotFoundException;

class PromotionPackageController extends BaseController
{
    protected $packageModel;

    public function __construct()
    {
        $this->packageModel = new PromotionPackageModel();
    }

    /**
     * List all packages
     */
    public function index()
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $packages = $this->packageModel->findAll();

        return view('admin/packages/index', [
            'packages' => $packages
        ]);
    }

    /**
     * Show form to create new package
     */
    public function new()
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        return view('admin/packages/form', [
            'package' => null
        ]);
    }

    /**
     * Save new package
     */
    public function create()
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $data = $this->request->getPost();

        // Basic validation
        if (empty($data['nome']) || empty($data['chave']) || empty($data['preco'])) {
            return redirect()->back()->withInput()->with('error', 'Preencha todos os campos obrigatórios.');
        }

        // Handle price format (PT-BR to float)
        $data['preco'] = (float) str_replace(['.', ','], ['', '.'], $data['preco']);

        if ($this->packageModel->save($data)) {
            return redirect()->to('admin/packages')->with('message', 'Pacote criado com sucesso.');
        }

        return redirect()->back()->withInput()->with('errors', $this->packageModel->errors());
    }

    /**
     * Show form to edit package
     */
    public function edit($id)
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $package = $this->packageModel->find($id);
        if (!$package) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/packages/form', [
            'package' => $package
        ]);
    }

    /**
     * Update package
     */
    public function update($id)
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin', 'admin')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $data = $this->request->getPost();
        $data['id'] = $id;

        // Handle price format
        if (isset($data['preco'])) {
             $data['preco'] = (float) str_replace(['.', ','], ['', '.'], $data['preco']);
        }

        if ($this->packageModel->save($data)) {
            return redirect()->to('admin/packages')->with('message', 'Pacote atualizado com sucesso.');
        }

        return redirect()->back()->withInput()->with('errors', $this->packageModel->errors());
    }

    /**
     * Delete package
     */
    public function delete($id)
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin', 'admin')) {
             if ($this->request->isAJAX()) {
                 return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
             }
             return redirect()->back()->with('error', 'Acesso negado.');
        }

        if ($this->packageModel->delete($id)) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['success' => true, 'message' => 'Pacote excluído.']);
            }
            return redirect()->to('admin/packages')->with('message', 'Pacote excluído.');
        }

        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['success' => false, 'message' => 'Erro ao excluir.']);
        }
        return redirect()->back()->with('error', 'Erro ao excluir.');
    }
}
