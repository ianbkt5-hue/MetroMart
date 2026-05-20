<?php
// api/auth/login.php  — POST {email, password}
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$body  = body();
$email = trim($body['email'] ?? '');
$pass  = $body['password'] ?? '';

if (!$email || !$pass) json_err('Email and password are required');

$stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password'])) {
    json_err('Wrong email or password', 401);
}

// Store safe subset in session (never store the hash)
$_SESSION['user'] = [
    'id'    => $user['id'],
    'email' => $user['email'],
    'role'  => $user['role'],
    'name'  => $user['name'],
];

$redirect = match($user['role']) {
    'admin'    => '/pages/admin-dashboard.html',
    'employee' => '/pages/employee-dashboard.html',
    'rider'    => '/pages/rider-dashboard.html',
    default    => '/pages/customer-dashboard.html',
};

json_ok(['redirect' => $redirect, 'role' => $user['role'], 'name' => $user['name']]);