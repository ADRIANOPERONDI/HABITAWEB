<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class GeoReset extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'db:geo-reset';
    protected $description = 'Limpa todas as propriedades antigas e re-roda os seeders geoespaciais com coordenadas reais e dispersas';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        
        CLI::write("\n=== INICIANDO RESET DE PROPRIEDADES GEOESPACIAIS ===\n", 'yellow');

        // 1. Limpar tabelas de forma robusta no PostgreSQL usando CASCADE
        CLI::write("Limpando banco de dados de imóveis com TRUNCATE CASCADE...", 'blue');
        
        try {
            // Tenta limpar todas as tabelas associadas reiniciando IDs
            $db->query("TRUNCATE TABLE property_media, leads, properties RESTART IDENTITY CASCADE");
            CLI::write("Banco de dados de imóveis limpo com sucesso e IDs reiniciados!\n", 'green');
        } catch (\Exception $e) {
            CLI::write("Erro ao executar TRUNCATE CASCADE: " . $e->getMessage() . ". Tentando DELETE individual ordenado...", 'yellow');
            try {
                $db->query("DELETE FROM property_media");
                if ($db->tableExists('property_features')) {
                    $db->query("DELETE FROM property_features");
                }
                if ($db->tableExists('property_alerts')) {
                    $db->query("DELETE FROM property_alerts");
                }
                if ($db->tableExists('leads')) {
                    $db->query("DELETE FROM leads");
                }
                $db->query("DELETE FROM properties");
                CLI::write("Banco de dados de imóveis limpo com DELETE individual!\n", 'green');
            } catch (\Exception $ex) {
                CLI::write("Falha crítica ao limpar banco: " . $ex->getMessage(), 'red');
            }
        }

        // 2. Executar Seeders
        CLI::write("Executando PropertyDemoSeeder...", 'yellow');
        $seeder = \Config\Database::seeder();
        try {
            $seeder->call('App\Database\Seeds\PropertyDemoSeeder');
        } catch (\Exception $e) {
            CLI::write("Erro no PropertyDemoSeeder: " . $e->getMessage(), 'red');
        }

        CLI::write("Executando AdminPropertySeeder...", 'yellow');
        try {
            $seeder->call('App\Database\Seeds\AdminPropertySeeder');
        } catch (\Exception $e) {
            CLI::write("Erro no AdminPropertySeeder: " . $e->getMessage(), 'red');
        }

        CLI::write("Executando MapPropertiesSeeder...", 'yellow');
        try {
            $seeder->call('App\Database\Seeds\MapPropertiesSeeder');
        } catch (\Exception $e) {
            CLI::write("Erro no MapPropertiesSeeder: " . $e->getMessage(), 'red');
        }

        // 3. Limpar caches de busca para que os novos bairros e cidades reflitam instantaneamente
        CLI::write("\nLimpando caches de busca...", 'blue');
        cache()->delete('search_filter_options');
        cache()->delete('filter_cities');
        cache()->delete('filter_neighborhoods');
        cache()->delete('filter_types');
        cache()->clean();

        CLI::write("\n=== RESET E SEEDING GEOESPACIAL CONCLUÍDOS COM SUCESSO! ===\n", 'green');
    }
}
