<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id'] ?? param('id', 0));
$body = body();
$action = $_GET['action'] ?? ($body['action'] ?? '');

try {
    switch ($method) {
        case 'GET':
            require_auth(['admin']);
            if ($id) {
                $s = db()->prepare('SELECT * FROM rider_applications WHERE id = ?');
                $s->execute([$id]);
                $row = $s->fetch();
                if (!$row) json_err('Not found', 404);
                json_ok($row);
            }
            $rows = db()->query('SELECT * FROM rider_applications ORDER BY created_at DESC')->fetchAll();
            json_ok($rows);

        case 'POST':
            // If admin actions: approve/reject
            if ($action === 'approve' || $action === 'reject') {
                require_auth(['admin']);
                if (!$id) json_err('id required', 400);
                $db = db();
                if ($action === 'reject') {
                    $stmt = $db->prepare('UPDATE rider_applications SET status = "rejected", admin_note = ? WHERE id = ?');
                    $stmt->execute([trim($body['admin_note'] ?? $body['note'] ?? $_POST['note'] ?? ''), $id]);
                    json_ok(['message' => 'Application rejected']);
                }

                // Approve: create user + rider record
                $app = $db->prepare('SELECT * FROM rider_applications WHERE id = ? LIMIT 1');
                $app->execute([$id]);
                $appRow = $app->fetch();
                if (!$appRow) json_err('Application not found', 404);

                $db->beginTransaction();

                $email = trim($appRow['email'] ?? '');
                $fname = trim($appRow['fname']);
                $lname = trim($appRow['lname']);
                $phone = normalizePHMobile($appRow['phone'] ?? '');

                $existing = $db->prepare('SELECT id, role FROM users WHERE email = ?');
                $existing->execute([$email]);
                $userRow = $existing->fetch();

                $tempPass = null;
                $forcePwdChange = false;
                if ($userRow) {
                    if ($userRow['role'] !== 'rider') json_err('Email already registered with different role', 409);
                    $db->prepare('UPDATE users SET status = "active", name = ? WHERE id = ?')
                       ->execute(["$fname $lname", $userRow['id']]);
                    $uid = $userRow['id'];
                } else {
                    $tempPass = bin2hex(random_bytes(5));
                    $hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $db->prepare('INSERT INTO users (email,password,role,status,name) VALUES (?,?,?,?,?)')
                       ->execute([$email, $hash, 'rider', 'active', "$fname $lname"]);
                    $uid = (int) $db->lastInsertId();
                    $forcePwdChange = true;
                }

                // Insert into riders table
                $db->prepare('INSERT INTO riders (id,fname,lname,phone,vehicle_type,profile_image) VALUES (?,?,?,?,?,?)')
                   ->execute([$uid, $fname, $lname, $phone, $appRow['vehicle_type'] ?? 'motorcycle', $appRow['photo_path'] ?? null]);

                // Mark application approved
                $note = trim($body['admin_note'] ?? $body['note'] ?? $_POST['admin_note'] ?? $_POST['note'] ?? '');
                $db->prepare('UPDATE rider_applications SET status = "approved", admin_note = ? WHERE id = ?')
                    ->execute([$note, $id]);

                if ($forcePwdChange) {
                    $db->prepare('UPDATE users SET force_password_change = 1 WHERE id = ?')->execute([$uid]);
                }

                if (!empty($email) && $tempPass !== null) {
                    $subject = 'MetroMart — Rider Application Approved';
                    $message = "Hello {$fname} {$lname},\n\nYour rider application has been approved. You can log in with:\n\nEmail: {$email}\nTemporary password: {$tempPass}\n\nPlease change your password on first login.\n\n— MetroMart Team";
                    @mail($email, $subject, $message, "From: no-reply@metromart.local");
                }

                $db->commit();
                json_ok(['message' => 'Application approved', 'user_id' => $uid, 'temp_password' => $tempPass]);
            }

            // Public submission (form-data)
            $isForm = !empty($_POST) || !empty($_FILES);
            if (!$isForm) json_err('Invalid submission', 400);

            $fname = trim($_POST['fname'] ?? '');
            $lname = trim($_POST['lname'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $vehicle = trim($_POST['vehicle_type'] ?? 'motorcycle');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm'] ?? '';

            if (!$fname || !$lname) json_err('First and last name required', 400);
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Valid email is required', 400);
            if (!$password || !$confirm) json_err('Password and confirmation are required', 400);
            if ($password !== $confirm) json_err('Passwords do not match', 400);
            if (strlen($password) < 8) json_err('Password must be at least 8 characters', 400);
            if (!preg_match('/[A-Z]/', $password)) json_err('Password must contain at least one uppercase letter', 400);
            if (!preg_match('/[0-9]/', $password)) json_err('Password must contain at least one number', 400);
            if (!preg_match('/[^A-Za-z0-9]/', $password)) json_err('Password must contain at least one special character', 400);
            if (!$phone || !preg_match('/^[0-9]{10}$/', $phone)) json_err('Phone must be a valid 10-digit Philippine number', 400);

            $db = db();
            $dup = $db->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
            $dup->execute([$email]);
            $dupRow = $dup->fetch();
            if ($dupRow) {
                if ($dupRow['role'] !== 'rider') {
                    json_err('Email already registered with a different account type', 409);
                }
                json_err('Email already registered. Please login to check your application status.', 409);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO users (email,password,role,status,name) VALUES (?,?,?,?,?)')
                   ->execute([$email, $hash, 'rider', 'inactive', "$fname $lname"]);
                $uid = (int)$db->lastInsertId();

                $license = save_upload('license', 'rider_applications');
                $photo   = save_upload('photo', 'rider_applications');

                $stmt = $db->prepare('INSERT INTO rider_applications (fname,lname,email,phone,vehicle_type,license_path,photo_path) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$fname,$lname,$email,$phone,$vehicle,$license,$photo]);
                $appId = (int)$db->lastInsertId();
                $db->commit();
                json_ok(['id' => $appId], 201);
            } catch (Throwable $e) {
                $db->rollBack();
                json_err('Submission failed: ' . $e->getMessage(), 500);
            }

        default:
            json_err('Method not allowed', 405);
    }
} catch (Throwable $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}
