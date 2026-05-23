<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CheckProperties extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'db:check-properties';
    protected $description = 'Analisa a quantidade de propriedades e coordenadas por cidade no banco de dados';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        
        // 1. Contagem geral
        $query = $db->query("
            SELECT 
                cidade, 
                COUNT(*) as total, 
                SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) as com_coordenadas,
                SUM(CASE WHEN latitude IS NULL OR longitude IS NULL THEN 1 ELSE 0 END) as sem_coordenadas
            FROM properties 
            WHERE deleted_at IS NULL
            GROUP BY cidade
            ORDER BY total DESC
        ");
        
        CLI::write("\n--- ANÁLISE DE PROPRIEDADES POR CIDADE ---\n", 'yellow');
        
        $results = $query->getResultArray();
        if (empty($results)) {
            CLI::write("Nenhuma propriedade encontrada no banco de dados.", 'red');
            return;
        }

        // Tabela formatada
        $thead = ['Cidade', 'Total', 'Com Coordenadas', 'Sem Coordenadas'];
        $tbody = [];
        foreach ($results as $row) {
            $tbody[] = [
                $row['cidade'] ?: '[Sem Cidade]',
                $row['total'],
                $row['com_coordenadas'],
                $row['sem_coordenadas']
            ];
        }
        
        CLI::table($tbody, $thead);
        
        // 3. Detalhes das contas existentes
        CLI::write("\n--- CONTAS EXISTENTES NO BANCO ---\n", 'yellow');
        $accQuery = $db->query("SELECT id, nome, tipo_conta, status FROM accounts");
        $accResults = $accQuery->getResultArray();
        if (empty($accResults)) {
            CLI::write("Nenhuma conta encontrada.", 'red');
        } else {
            $theadAcc = ['ID', 'Nome', 'Tipo Conta', 'Status'];
            $tbodyAcc = [];
            foreach ($accResults as $row) {
                $tbodyAcc[] = [
                    $row['id'],
                    $row['nome'],
                    $row['tipo_conta'],
                    $row['status']
                ];
            }
            CLI::table($tbodyAcc, $theadAcc);
        }
        
        // 4. Detalhes das propriedades e suas coordenadas
        CLI::write("\n--- COORDENADAS DAS PROPRIEDADES POR CIDADE ---\n", 'yellow');
        $propDetailsQuery = $db->query("SELECT id, account_id, cidade, bairro, latitude, longitude FROM properties WHERE deleted_at IS NULL ORDER BY cidade ASC, id ASC");
        $propDetails = $propDetailsQuery->getResultArray();
        if (empty($propDetails)) {
            CLI::write("Nenhuma propriedade detalhada encontrada.", 'red');
        } else {
            $theadProp = ['ID', 'Account ID', 'Cidade', 'Bairro', 'Latitude', 'Longitude'];
            $tbodyProp = [];
            foreach ($propDetails as $row) {
                $tbodyProp[] = [
                    $row['id'],
                    $row['account_id'],
                    $row['cidade'],
                    $row['bairro'],
                    $row['latitude'],
                    $row['longitude']
                ];
            }
            CLI::table($tbodyProp, $theadProp);
        }
    }
}
