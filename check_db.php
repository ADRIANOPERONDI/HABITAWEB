<?php
// Script para rodar no Spark CLI para verificar as propriedades
define('FCPATH', __DIR__ . '/public/');
require_once __DIR__ . '/app/Config/Paths.php';
$paths = new Config\Paths();
require_once $paths->systemDirectory . '/bootstrap.php';

$db = \Config\Database::connect();
$query = $db->query("SELECT cidade, COUNT(*) as total, SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) as com_coordenadas FROM properties GROUP BY cidade");
print_r($query->getResultArray());
