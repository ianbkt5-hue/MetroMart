<?php
// =============================================
// METROMART — Employees API
// File: api/employees/index.php
//
// GET  ?action=me   → own profile (employee)
// GET  (admin)      → list all
// POST (admin)      → create / update
// DELETE ?id (admin)→ deactivate
// =============================================

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth(['admin', 'employee']);
$id     = (int) ($_GET['id'] ?? param('id', 0));
$action = $_GET['action'] ?? '';

// ── Employee: get own profile (includes merchant_id) ──
if ($action === 'me' && $user['role'] !== 'employee') {
    json_err('Employee profile is only available to employee accounts', 403);
}

if (($action === 'me' && $user['role'] === 'employee') || ($method === 'GET' && $user['role'] === 'employee')) {
    $s = db()->prepare(
        'SELECT e.*, e.profile_image, u.email, m.name AS merchant_name, m.address AS merchant_address,
                m.contact AS merchant_contact, m.image_path AS merchant_image
         FROM employees e
         JOIN users u ON u.id = e.id
         JOIN merchants m ON m.id = e.merchant_id
         WHERE e.id = ?'
    );
    $s->execute([$user['id']]);
    $row = $s->fetch();
    if (!$row) json_err('Employee profile not found', 404);
    json_ok($row);
}

// If requesting a single employee by id (admin)
if ($method === 'GET' && $id && $user['role'] === 'admin') {
    require_auth(['admin']);
    $s = db()->prepare(
        'SELECT u.id, u.email, u.status,
                e.fname, e.lname, e.phone, e.position, e.profile_image, e.merchant_id,
                m.name AS merchant_name, m.address AS merchant_address, m.contact AS merchant_contact, m.image_path AS merchant_image
         FROM employees e
         JOIN users u     ON u.id  = e.id
         JOIN merchants m ON m.id  = e.merchant_id
         WHERE e.id = ? LIMIT 1'
    );
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) json_err('Employee not found', 404);
    json_ok($row);
}

switch ($method) {

    case 'GET':
        require_auth(['admin']);
        $rows = db()->query(
            'SELECT u.id, u.email, u.status,
                    e.fname, e.lname, e.phone, e.position, e.profile_image,
                    m.name AS merchant_name, e.merchant_id
             FROM employees e
             JOIN users u     ON u.id  = e.id
             JOIN merchants m ON m.id  = e.merchant_id
             ORDER BY e.fname'
        )->fetchAll();
        json_ok($rows);

    case 'POST':
        $user   = require_auth(['admin','employee']);
        $db     = db();
        $isForm = !empty($_POST) || !empty($_FILES);
        $b      = $isForm ? $_POST : body();

        // ── Employee updating own profile ─────────────────
        if ($user['role'] === 'employee') {
            $fields  = [];
            $vals    = [];

            if (!empty($b['fname'])) { $fields[] = 'fname = ?'; $vals[] = trim($b['fname']); }
            if (!empty($b['lname'])) { $fields[] = 'lname = ?'; $vals[] = trim($b['lname']); }
            if (!empty($b['phone'])) {
                $rawPhone = trim($b['phone']);
                if (!preg_match('/^[0-9]{10}$/', $rawPhone)) {
                    json_err('Phone must be exactly 10 digits with no letters or special characters', 400);
                }
                $phone = normalizePHMobile($rawPhone);
                if (!isValidPHMobile($phone)) {
                    json_err('Phone must be a valid Philippine mobile number', 400);
                }
                if (isPhoneTaken($db, $phone, ['employees' => $user['id']])) {
                    json_err('Phone number already used by another account', 409);
                }
                $fields[] = 'phone = ?';
                $vals[]   = $phone;
            }
            if (!empty($b['position'])) { $fields[] = 'position = ?'; $vals[] = trim($b['position']); }
            // profile image uploads disabled

            if ($fields) {
                $vals[] = $user['id'];
                $db->prepare('UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
            }

            if (!empty($b['fname']) || !empty($b['lname'])) {
                $nameSnap = $db->prepare('SELECT fname, lname FROM employees WHERE id = ?');
                $nameSnap->execute([$user['id']]);
                $current  = $nameSnap->fetch();
                $newName  = trim(($b['fname'] ?? $current['fname']) . ' ' . ($b['lname'] ?? $current['lname']));
                $db->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$newName, $user['id']]);
            }

            json_ok();
        }

        $fname    = trim($b['fname']        ?? '');
        $lname    = trim($b['lname']        ?? '');
        $email    = trim($b['email']        ?? '');
        $pass     = $b['password']           ?? '';
        $position = trim($b['position']     ?? '');
        $phone    = trim($b['phone']        ?? '');
        $mId      = (int)($b['merchant_id'] ?? 0);
        // profile image uploads disabled for admin create/update

        if ($phone) {
            $phone = normalizePHMobile($phone);
            if (!isValidPHMobile($phone)) {
                json_err('Phone must be a valid Philippine mobile number', 400);
            }
            if (isPhoneTaken($db, $phone, ['employees' => $id])) {
                json_err('Phone number already used by another account', 409);
            }
        }

        if (!$fname || !$lname || !$email || !$mId) {
            json_err('First name, last name, email, and merchant are required');
        }

        if ($id) {
            $fields = ['fname = ?', 'lname = ?', 'phone = ?', 'position = ?', 'merchant_id = ?'];
            $vals   = [$fname, $lname, $phone, $position, $mId];
            // profile image uploads disabled
            $vals[] = $id;
            db()->prepare('UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = ?')
               ->execute($vals);
            db()->prepare('UPDATE users SET name=? WHERE id=?')
               ->execute(["$fname $lname", $id]);
            json_ok();
        }

        // CREATE new employee
        if (strlen($pass) < 6) json_err('Password must be at least 6 characters');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email address');

        $existing = $db->prepare('SELECT id, role FROM users WHERE email = ?');
        $existing->execute([$email]);
        $userRow = $existing->fetch();

        if ($userRow) {
            if ($userRow['role'] !== 'employee') {
                json_err('Email already registered', 409);
            }

            $existingEmployee = $db->prepare('SELECT id FROM employees WHERE id = ?');
            $existingEmployee->execute([$userRow['id']]);
            if ($existingEmployee->fetch()) {
                json_err('Email already registered', 409);
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->beginTransaction();
            try {
                $db->prepare('UPDATE users SET password = ?, status = "active", name = ? WHERE id = ?')
                   ->execute([$hash, "$fname $lname", $userRow['id']]);
                $db->prepare(
                    'INSERT INTO employees (id,fname,lname,phone,position,merchant_id) VALUES (?,?,?,?,?,?)'
                )->execute([$userRow['id'], $fname, $lname, $phone, $position, $mId]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                json_err($e->getMessage(), 500);
            }
            json_ok(['id' => $userRow['id']], 201);
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO users (email,password,role,status,name) VALUES (?,?,?,?,?)')
               ->execute([$email, $hash, 'employee', 'active', "$fname $lname"]);
            $uid = (int) $db->lastInsertId();
            $db->prepare(
                'INSERT INTO employees (id,fname,lname,phone,position,merchant_id) VALUES (?,?,?,?,?,?)'
            )->execute([$uid, $fname, $lname, $phone, $position, $mId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json_err($e->getMessage(), 500);
        }
        json_ok(['id' => $uid], 201);

    case 'DELETE':
        require_auth(['admin']);
        if (!$id) json_err('id required');
        db()->prepare('UPDATE users SET status = "inactive" WHERE id = ?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}