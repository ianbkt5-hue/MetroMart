<?php
// api/auth/register.php  — POST (customer self-registration)
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$body    = body();
$fname   = trim($body['fname']   ?? '');
$lname   = trim($body['lname']   ?? '');
$email   = trim($body['email']   ?? '');
$phone   = trim($body['phone']   ?? '');
$address = trim($body['address'] ?? '');
$pass    = $body['password']      ?? '';
$confirm = $body['confirm']       ?? '';

if (!$fname || !$lname || !$email || !$pass) json_err('All fields are required');
if (strlen($pass) < 6)  json_err('Password must be at least 6 characters');
if ($pass !== $confirm) json_err('Passwords do not match');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email address');

$db = db();

// Check duplicate
$dup = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$dup->execute([$email]);
if ($dup->fetch()) json_err('Email already registered', 409);

$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO users (email, password, role, status, name) VALUES (?,?,?,?,?)')
       ->execute([$email, $hash, 'customer', 'active', "$fname $lname"]);
    $uid = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO customers (id, fname, lname, phone, address) VALUES (?,?,?,?,?)')
       ->execute([$uid, $fname, $lname, $phone, $address]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    json_err('Registration failed: ' . $e->getMessage(), 500);
}

$_SESSION['user'] = ['id' => $uid, 'email' => $email, 'role' => 'customer', 'name' => "$fname $lname"];
json_ok(['redirect' => '/pages/customer-dashboard.html'], 201);