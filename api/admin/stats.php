<?php
// api/admin/stats.php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

require_auth(['admin']);
$db = db();

json_ok([
    'merchants' => (int) $db->query('SELECT COUNT(*) FROM merchants')->fetchColumn(),
    'employees' => (int) $db->query('SELECT COUNT(*) FROM employees')->fetchColumn(),
    'riders'    => (int) $db->query('SELECT COUNT(*) FROM riders')->fetchColumn(),
    'customers' => (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'orders'    => (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'products'  => (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'revenue'   => (float) $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='Delivered'")->fetchColumn(),
    'pending'   => (int) $db->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn(),
]);