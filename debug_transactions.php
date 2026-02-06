<?php
$db = \Config\Database::connect();
$query = $db->query("SELECT id, account_id, subscription_id, gateway_transaction_id, status, type, amount, created_at, metadata, description FROM payment_transactions WHERE subscription_id = 26 OR account_id = (SELECT account_id FROM subscriptions WHERE id = 26)");
$results = $query->getResultArray();
echo json_encode($results, JSON_PRETTY_PRINT);
