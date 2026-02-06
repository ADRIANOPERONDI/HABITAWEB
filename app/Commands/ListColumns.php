<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ListColumns extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'db:list-columns';
    protected $description = 'Lists columns of a table';

    public function run(array $params)
    {
        $table = array_shift($params);
        if (empty($table)) {
           CLI::error("Specify table"); return; 
        }
        
        $db = \Config\Database::connect();
        $query = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = ?", [$table]);
        
        foreach ($query->getResult() as $row) {
            CLI::write($row->column_name);
        }
    }
}
