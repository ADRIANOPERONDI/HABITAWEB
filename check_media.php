<?php
require 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/bootstrap.php';

$db = \Config\Database::connect();
$query = $db->table('property_media')->where('property_id', 1)->get();
$results = $query->getResultArray();

echo "Total media for property 1: " . count($results) . "\n";
foreach ($results as $row) {
    echo "ID: " . $row['id'] . " | URL: " . $row['url'] . " | Principal: " . var_export($row['principal'], true) . " | Ordem: " . $row['ordem'] . "\n";
}
