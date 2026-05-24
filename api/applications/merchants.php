<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

function ensureMerchantApplicationImageColumn($db) {
    $stmt = $db->prepare("SHOW COLUMNS FROM merchant_applications LIKE 'merchant_image_path'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE merchant_applications ADD COLUMN merchant_image_path VARCHAR(512) NULL AFTER employee_id_path");
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id'] ?? param('id', 0));
$body = body();
$action = $_GET['action'] ?? ($body['action'] ?? '');

try {
    switch ($method) {
        case 'GET':
            require_auth(['admin']);
            if ($id) {
                $s = db()->prepare('SELECT * FROM merchant_applications WHERE id = ?');
                $s->execute([$id]);
                $row = $s->fetch();
                if (!$row) json_err('Not found', 404);
                json_ok($row);
            }
            $rows = db()->query('SELECT * FROM merchant_applications ORDER BY created_at DESC')->fetchAll();
            json_ok($rows);

        case 'POST':
            // Admin actions
            if ($action === 'approve' || $action === 'reject') {
                require_auth(['admin']);
                if (!$id) json_err('id required', 400);
                $db = db();
                if ($action === 'reject') {
                    $stmt = $db->prepare('UPDATE merchant_applications SET status = "rejected", admin_note = ? WHERE id = ?');
                    $stmt->execute([trim($body['admin_note'] ?? $body['note'] ?? $_POST['note'] ?? ''), $id]);
                    json_ok(['message' => 'Application rejected']);
                }

                // Approve: create merchant + employee user
                ensureMerchantApplicationImageColumn($db);
                $app = $db->prepare('SELECT * FROM merchant_applications WHERE id = ? LIMIT 1');
                $app->execute([$id]);
                $appRow = $app->fetch();
                if (!$appRow) json_err('Application not found', 404);

                $mName = trim($appRow['merchant_name']);
                $addr  = trim($appRow['address'] ?? '');
                $lat   = $appRow['latitude'] ?: null;
                $lng   = $appRow['longitude'] ?: null;
                $mImg  = trim($appRow['merchant_image_path'] ?? '');

                $efname = trim($appRow['employee_fname']);
                $elname = trim($appRow['employee_lname']);
                $eemail = trim($appRow['employee_email'] ?? '');
                $ephone = normalizePHMobile($appRow['employee_phone'] ?? '');

                $db->beginTransaction();
                try {
                    // create merchant
                    $db->prepare('INSERT INTO merchants (name,address,latitude,longitude,contact,created_by,image_path) VALUES (?,?,?,?,?,?,?)')
                       ->execute([$mName, $addr, $lat, $lng, $ephone, $_SESSION['user']['id'] ?? null, $mImg ?: null]);
                    $mid = (int)$db->lastInsertId();

                    $tempPass = null;
                    $forcePwdChange = false;
                    if ($eemail) {
                        $existing = $db->prepare('SELECT id, role FROM users WHERE email = ?');
                        $existing->execute([$eemail]);
                        $userRow = $existing->fetch();
                        if ($userRow && $userRow['role'] !== 'employee') json_err('Employee email already registered with different role', 409);
                    } else {
                        $userRow = null;
                    }

                    if ($userRow) {
                        $db->prepare('UPDATE users SET status = "active", name = ? WHERE id = ?')
                           ->execute(["$efname $elname", $userRow['id']]);
                        $uid = $userRow['id'];
                    } else {
                        $tempPass = bin2hex(random_bytes(5));
                        $hash = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
                        $emailForUser = $eemail ?: ("merchant_emp_" . time() . "@local");
                        $db->prepare('INSERT INTO users (email,password,role,status,name) VALUES (?,?,?,?,?)')
                           ->execute([$emailForUser, $hash, 'employee', 'active', "$efname $elname"]);
                        $uid = (int)$db->lastInsertId();
                        $forcePwdChange = true;
                    }

                    // insert into employees table
                    $db->prepare('INSERT INTO employees (id,fname,lname,phone,position,merchant_id) VALUES (?,?,?,?,?,?)')
                       ->execute([$uid, $efname, $elname, $ephone, 'Manager', $mid]);

                    // update application status
                    $note = trim($body['admin_note'] ?? $body['note'] ?? $_POST['admin_note'] ?? $_POST['note'] ?? '');
                    $db->prepare('UPDATE merchant_applications SET status = "approved", admin_note = ? WHERE id = ?')
                        ->execute([$note, $id]);

                    if ($forcePwdChange) {
                        $db->prepare('UPDATE users SET force_password_change = 1 WHERE id = ?')->execute([$uid]);
                    }

                    if (!empty($eemail) && $tempPass !== null) {
                        $subject = 'MetroMart — Merchant Application Approved';
                        $message = "Hello {$efname} {$elname},\n\nYour store application has been approved. Merchant: {$mName}\nYou can log in with:\nEmail: {$eemail}\nTemporary password: {$tempPass}\n\nPlease change your password on first login.\n\n— MetroMart Team";
                        @mail($eemail, $subject, $message, "From: no-reply@metromart.local");
                    }

                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    json_err('Server: ' . $e->getMessage(), 500);
                }

                json_ok(['message' => 'Application approved', 'merchant_id' => $mid, 'employee_user_id' => $uid, 'temp_password' => $tempPass]);
            }

            // Public submission
            $isForm = !empty($_POST) || !empty($_FILES);
            if (!$isForm) json_err('Invalid submission', 400);

            $mName = trim($_POST['merchant_name'] ?? '');
            $addr  = trim($_POST['address'] ?? '');
            $lat   = isset($_POST['latitude']) ? trim($_POST['latitude']) : null;
            $lng   = isset($_POST['longitude']) ? trim($_POST['longitude']) : null;
            $efname = trim($_POST['employee_fname'] ?? '');
            $elname = trim($_POST['employee_lname'] ?? '');
            $eemail = trim($_POST['employee_email'] ?? '');
            $ephone = trim($_POST['employee_phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm'] ?? '';

            if (!$mName || !$efname || !$elname) json_err('Merchant name and employee name are required', 400);
            if (!$eemail || !filter_var($eemail, FILTER_VALIDATE_EMAIL)) json_err('Valid employee email is required', 400);
            if (!$password || !$confirm) json_err('Password and confirmation are required', 400);
            if ($password !== $confirm) json_err('Passwords do not match', 400);
            if (strlen($password) < 8) json_err('Password must be at least 8 characters', 400);
            if (!preg_match('/[A-Z]/', $password)) json_err('Password must contain at least one uppercase letter', 400);
            if (!preg_match('/[0-9]/', $password)) json_err('Password must contain at least one number', 400);
            if (!preg_match('/[^A-Za-z0-9]/', $password)) json_err('Password must contain at least one special character', 400);
            if (!$ephone || !preg_match('/^[0-9]{10}$/', $ephone)) json_err('Employee phone must be a valid 10-digit Philippine number', 400);

            $db = db();
            $dup = $db->prepare('SELECT id, role FROM users WHERE email = ? LIMIT 1');
            $dup->execute([$eemail]);
            $dupRow = $dup->fetch();
            if ($dupRow) {
                if ($dupRow['role'] !== 'employee') {
                    json_err('Email already registered with a different account type', 409);
                }
                json_err('Email already registered. Please login to check your application status.', 409);
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO users (email,password,role,status,name) VALUES (?,?,?,?,?)')
                   ->execute([$eemail, $hash, 'employee', 'inactive', "$efname $elname"]);
                $uid = (int)$db->lastInsertId();

                $mImg = save_upload('merchant_image', 'merchant_applications');
                $face = save_upload('employee_face', 'merchant_applications');
                $idimg = save_upload('employee_id', 'merchant_applications');

                ensureMerchantApplicationImageColumn($db);
                $stmt = $db->prepare('INSERT INTO merchant_applications (merchant_name,address,latitude,longitude,employee_fname,employee_lname,employee_phone,employee_email,employee_face_path,employee_id_path,merchant_image_path) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$mName,$addr,$lat,$lng,$efname,$elname,$ephone,$eemail,$face,$idimg,$mImg]);
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
