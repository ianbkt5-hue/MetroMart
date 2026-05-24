<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$user = require_auth(['employee','rider']);
$db = db();

if ($user['role'] === 'rider') {
    $stmt = $db->prepare('SELECT id,fname,lname,email,phone,vehicle_type,status,admin_note,created_at FROM rider_applications WHERE email = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$user['email']]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) json_err('No rider application found for this account', 404);
    json_ok($app);
}

$stmt = $db->prepare('SELECT id,merchant_name,address,employee_fname,employee_lname,employee_email,employee_phone,status,admin_note,created_at FROM merchant_applications WHERE employee_email = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$user['email']]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) json_err('No merchant application found for this account', 404);
json_ok($app);
