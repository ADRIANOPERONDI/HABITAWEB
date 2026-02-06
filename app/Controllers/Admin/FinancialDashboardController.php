<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class FinancialDashboardController extends BaseController
{
    protected $transactionModel;
    protected $subscriptionModel;

    public function index()
    {
        $financialService = new \App\Services\FinancialService();
        $data = $financialService->getFinancialDashboardData();

        return view('admin/financial/dashboard', $data);
    }
}
