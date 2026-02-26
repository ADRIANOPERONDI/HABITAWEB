<?php
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';

$db = \Config\Database::connect();
$transactions = $db->table('payment_transactions')->orderBy('id', 'DESC')->limit(10)->get()->getResultArray();
print_r($transactions);

$subs = $db->table('subscriptions')->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();
print_r($subs);
