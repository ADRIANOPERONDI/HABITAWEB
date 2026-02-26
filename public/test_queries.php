<?php
require 'index.php';
$db = \Config\Database::connect();
$transactions = $db->table('payment_transactions')->orderBy('id', 'DESC')->limit(1)->get()->getResultArray();
echo "Transactions:\n";
print_r($transactions);

$subs = $db->table('subscriptions')->orderBy('id', 'DESC')->limit(1)->get()->getResultArray();
echo "\nSubscriptions:\n";
print_r($subs);
