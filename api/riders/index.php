<?php
// =============================================
// METROMART — Riders API
// File: api/riders/index.php
//
// GET  (admin)         → list all riders
// POST (admin)         → create rider
// POST (rider, self)   → update own profile/status/location
// DELETE ?id=N (admin) → deactivate
// =============================================

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id'] ?? 0);

switch ($method) {

    // ── List all riders (admin only) or return logged-in rider profile
    case 'GET':
        $user = require_auth(['admin','rider']);
        $db   = db();

        // Allow riders to fetch their wallet logs via ?logs=1 and optional ?type=cashout
        if ($user['role'] === 'rider' && isset($_GET['logs'])) {
            $type = isset($_GET['type']) ? trim($_GET['type']) : null;
            if ($type) {
                $stmt = $db->prepare('SELECT id, order_id, amount, type, description, created_at FROM rider_wallet_log WHERE rider_id = ? AND type = ? ORDER BY created_at DESC LIMIT 100');
                $stmt->execute([$user['id'], $type]);
            } else {
                $stmt = $db->prepare('SELECT id, order_id, amount, type, description, created_at FROM rider_wallet_log WHERE rider_id = ? ORDER BY created_at DESC LIMIT 100');
                $stmt->execute([$user['id']]);
            }
            json_ok($stmt->fetchAll());
        }

        if ($user['role'] === 'rider' && !$id) {
            $row = $db->prepare(
                'SELECT u.id, u.email, u.status,
                        COALESCE(r.fname, u.name) AS fname,
                        COALESCE(r.lname, "") AS lname,
                        r.phone, r.vehicle_type,
                        COALESCE(r.rider_status, "offline") AS rider_status,
                        r.current_lat, r.current_lng,
                        COALESCE(r.wallet_balance, 0.00) AS wallet_balance,
                        COALESCE(r.pending_cashouts, 0.00) AS pending_cashouts
                 FROM users u
                 LEFT JOIN riders r ON r.id = u.id
                 WHERE u.id = ? AND u.role = "rider"
                 LIMIT 1'
            );
            $row->execute([$user['id']]);
            json_ok($row->fetch() ?: []);
        }

        require_auth(['admin']);
        $rows = $db->query(
            'SELECT u.id, u.email, u.status,
                    COALESCE(r.fname, u.name) AS fname,
                    COALESCE(r.lname, "") AS lname,
                    r.phone, r.vehicle_type,
                    COALESCE(r.rider_status, "offline") AS rider_status,
                    r.current_lat, r.current_lng,
                    r.profile_image
             FROM users u
             LEFT JOIN riders r ON r.id = u.id
             WHERE u.role = "rider"
             ORDER BY COALESCE(r.fname, u.name)'
        )->fetchAll();
        json_ok($rows);

    // ── Create (admin) or update self (rider) ─
    case 'POST':
        $user = require_auth(['admin', 'rider']);
        $isForm = !empty($_POST) || !empty($_FILES);
        $b = $isForm ? $_POST : body();

        // ── Rider updating own profile or requesting cashout ─────────
        if ($user['role'] === 'rider') {
            $db = db();

            if (isset($b['cashout_amount'])) {
                $amount = (float)$b['cashout_amount'];
                if ($amount <= 0) {
                    json_err('Enter a valid cashout amount', 400);
                }

                $stmt = $db->prepare('SELECT wallet_balance FROM riders WHERE id = ? LIMIT 1');
                $stmt->execute([$user['id']]);
                $rider = $stmt->fetch();
                if (!$rider) json_err('Rider profile not found', 404);

                if ($amount > (float)$rider['wallet_balance']) {
                    json_err('Insufficient wallet balance', 400);
                }

                     // Optional: accept gcash number for play/verification (do not store pin)
                     $gcash = trim($b['gcash_number'] ?? '');
                     $gcashMasked = '';
                     if ($gcash && preg_match('/^9\d{9}$/', preg_replace('/\D/', '', $gcash))) {
                          $gcashMasked = '+63' . substr($gcash, 0, 1) . '*******' . substr($gcash, -2);
                     }

                     $db->beginTransaction();
                     $db->prepare('UPDATE riders SET wallet_balance = wallet_balance - ? WHERE id = ?')
                         ->execute([$amount, $user['id']]);
                     $db->prepare('INSERT INTO rider_cashouts (rider_id, amount, fee, receive_amount, status) VALUES (?,?,?,?,?)')
                         ->execute([$user['id'], $amount, 0.00, $amount, 'completed']);
                     $desc = 'Cashout of ₱' . number_format($amount, 2, '.', '');
                     if ($gcashMasked) $desc .= ' to ' . $gcashMasked;
                     $db->prepare('INSERT INTO rider_wallet_log (rider_id, order_id, amount, type, description) VALUES (?,?,?,?,?)')
                         ->execute([$user['id'], null, -$amount, 'cashout', $desc]);
                     $db->commit();

                     json_ok(['message' => 'Transaction Successful'], 201);
            }

            // profile image uploads disabled for riders

            $fields = [];
            $vals   = [];

            if (!empty($b['fname']))        { $fields[] = 'fname = ?';        $vals[] = trim($b['fname']); }
            if (!empty($b['lname']))        { $fields[] = 'lname = ?';        $vals[] = trim($b['lname']); }
            if (!empty($b['phone'])) {
                $rawPhone = trim($b['phone']);
                if (!preg_match('/^[0-9]{10}$/', $rawPhone)) {
                    json_err('Phone must be exactly 10 digits with no letters or special characters', 400);
                }
                $cleanPhone = normalizePHMobile($rawPhone);
                if (!isValidPHMobile($cleanPhone)) {
                    json_err('Phone must be a valid Philippine mobile number', 400);
                }
                if (isPhoneTaken($db, $cleanPhone, ['riders' => $user['id']])) {
                    json_err('Phone number already used by another account', 409);
                }
                $fields[] = 'phone = ?';
                $vals[] = $cleanPhone;
            }
            if (!empty($b['vehicle_type'])) { $fields[] = 'vehicle_type = ?'; $vals[] = $b['vehicle_type']; }
            if (!empty($b['rider_status'])) { $fields[] = 'rider_status = ?'; $vals[] = $b['rider_status']; }
            if (isset($b['current_lat']))   { $fields[] = 'current_lat = ?';  $vals[] = $b['current_lat']; }
            if (isset($b['current_lng']))   { $fields[] = 'current_lng = ?';  $vals[] = $b['current_lng']; }
            // profile image uploads disabled
            if ($fields) {
                $vals[] = $user['id'];
                $set    = implode(', ', $fields);
                $db->prepare("UPDATE riders SET {$set} WHERE id = ?")->execute($vals);
            }

            // Update display name in users table if name changed
            if (!empty($b['fname']) || !empty($b['lname'])) {
                $nameSnap = $db->prepare('SELECT fname, lname FROM riders WHERE id = ?');
                $nameSnap->execute([$user['id']]);
                $current = $nameSnap->fetch();
                $newName = trim(($b['fname'] ?? $current['fname']) . ' ' . ($b['lname'] ?? $current['lname']));
                $db->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$newName, $user['id']]);
            }

            json_ok();
        }

        // ── Admin: update existing rider ───────
        if ($id) {
            require_auth(['admin']);
            $db = db();
            $rawPhone = trim($b['phone'] ?? '');
            if ($rawPhone !== '' && !preg_match('/^[0-9]{10}$/', $rawPhone)) {
                json_err('Phone must be exactly 10 digits with no letters or special characters', 400);
            }
            $phone = normalizePHMobile($rawPhone);
            if ($phone) {
                if (!isValidPHMobile($phone)) {
                    json_err('Phone must be a valid Philippine mobile number', 400);
                }
                if (isPhoneTaken($db, $phone, ['riders' => $id])) {
                    json_err('Phone number already used by another account', 409);
                }
            }
            $db->prepare('UPDATE riders SET fname=?,lname=?,phone=?,vehicle_type=?,rider_status=? WHERE id=?')
               ->execute([
                   trim($b['fname']        ?? ''),
                   trim($b['lname']        ?? ''),
                   $phone,
                   $b['vehicle_type']      ?? 'motorcycle',
                   $b['rider_status']      ?? 'offline',
                   $id,
               ]);
            json_ok();
        }

        // ── Admin: create new rider ────────────
        require_auth(['admin']);
        $db      = db();
        $fname   = trim($b['fname']   ?? '');
        $lname   = trim($b['lname']   ?? '');
        $email   = trim($b['email']   ?? '');
        $pass    = $b['password']      ?? '';
        $rawPhone = trim($b['phone']   ?? '');
        if ($rawPhone !== '' && !preg_match('/^[0-9]{10}$/', $rawPhone)) {
            json_err('Phone must be exactly 10 digits with no letters or special characters', 400);
        }
        $phone   = normalizePHMobile($rawPhone);
        $vehicle = $b['vehicle_type'] ?? 'motorcycle';

        if ($phone) {
            if (!isValidPHMobile($phone)) json_err('Phone must be a valid Philippine mobile number', 400);
            if (isPhoneTaken($db, $phone)) json_err('Phone number already used by another account', 409);
        }

        if (!$fname || !$lname || !$email) json_err('First name, last name, and email are required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email address');
        if (strlen($pass) < 6) json_err('Password must be at least 6 characters');

        // profile image uploads disabled for admin create
        $db = db();
        $existing = $db->prepare('SELECT id, role FROM users WHERE email = ?');
        $existing->execute([$email]);
        $userRow = $existing->fetch();

        if ($userRow) {
            if ($userRow['role'] !== 'rider') {
                json_err('Email already registered', 409);
            }

            $existingRider = $db->prepare('SELECT id FROM riders WHERE id = ?');
            $existingRider->execute([$userRow['id']]);
            if ($existingRider->fetch()) {
                json_err('Email already registered', 409);
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->beginTransaction();
            try {
                $db->prepare('UPDATE users SET password = ?, status = "active", name = ? WHERE id = ?')
                   ->execute([$hash, "$fname $lname", $userRow['id']]);
                $db->prepare('INSERT INTO riders (id,fname,lname,phone,vehicle_type) VALUES (?,?,?,?,?)')
                    ->execute([$userRow['id'], $fname, $lname, $phone, $vehicle]);
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
               ->execute([$email, $hash, 'rider', 'active', "$fname $lname"]);
            $uid = (int) $db->lastInsertId();
                $db->prepare('INSERT INTO riders (id,fname,lname,phone,vehicle_type) VALUES (?,?,?,?,?)')
                    ->execute([$uid, $fname, $lname, $phone, $vehicle]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json_err($e->getMessage(), 500);
        }
        json_ok(['id' => $uid], 201);

    // ── Deactivate (admin) ────────────────────
    case 'DELETE':
        require_auth(['admin']);
        if (!$id) json_err('id required');
        db()->prepare('UPDATE users SET status = "inactive" WHERE id = ?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}