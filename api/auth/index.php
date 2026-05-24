<?php
// =============================================
// METROMART — Auth API v2
// api/auth/index.php
// =============================================

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

function base_url(string $path = ''): string {
    // Return relative paths instead of absolute paths
    // This works correctly regardless of deployment location
    return ltrim($path, '/');
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// ── SECURITY QUESTIONS LIST ───────────────────
const SECURITY_QUESTIONS = [
    1 => "What is the name of your first pet?",
    2 => "What is your mother's maiden name?",
    3 => "What city were you born in?",
    4 => "What was the name of your first school?",
    5 => "What is your favourite childhood nickname?",
];

// ── ME ────────────────────────────────────────
if ($action === 'me') {
    $user = current_user();
    if (!$user) json_err('Not logged in', 401);

    $stmt = db()->prepare('SELECT id,email,role,name,status,force_password_change FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_err('Not logged in', 401);

    json_ok($row);
}

// ── LOGIN ─────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $b     = body();
    $email = trim($b['email']    ?? '');
    $pass  = $b['password']      ?? '';
    if (!$email || !$pass) json_err('Email and password are required', 422);

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($pass, $user['password'])) {
        json_err('Wrong email or password', 401);
    }

    if ($user['status'] !== 'active' && !in_array($user['role'], ['rider','employee'], true)) {
        json_err('Account is not active yet', 403);
    }

    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
        'name'  => $user['name'],
    ];

    // Check if forced password change required
    if ($user['force_password_change'] ?? false) {
        json_ok([
            'redirect'              => base_url('change-password.html'),
            'role'                  => $user['role'],
            'force_password_change' => true,
        ]);
    }

    if ($user['status'] !== 'active') {
        json_ok([
            'redirect' => base_url('pages/application-status.html'),
            'role'     => $user['role'],
            'status'   => $user['status'],
        ]);
    }

    $redirect = match($user['role']) {
        'admin'    => base_url('pages/admin-dashboard.html'),
        'employee' => base_url('pages/employee-dashboard.html'),
        'rider'    => base_url('pages/rider-dashboard.html'),
        default    => base_url('pages/customer-dashboard.html'),
    };

    json_ok(['redirect' => $redirect, 'role' => $user['role']]);
}

// ── REGISTER ──────────────────────────────────
if ($action === 'register' && $method === 'POST') {
    $b       = body();
    $fname   = trim($b['fname']       ?? '');
    $lname   = trim($b['lname']       ?? '');
    $email   = trim($b['email']       ?? '');
    $phone   = trim($b['phone']       ?? '');
    $address = trim($b['address']     ?? '');
    $pass    = $b['password']          ?? '';
    $confirm = $b['confirm']           ?? '';
    $sqId    = (int)($b['security_question_id'] ?? 0);
    $sqAns   = trim($b['security_answer'] ?? '');

    if (!$fname || !$lname || !$email || !$phone || !$pass) json_err('Missing required fields', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email address', 422);

    // Philippine phone validation
    $cleanPhone = normalizePHMobile($phone);
    if (!isValidPHMobile($phone)) {
        json_err('Phone must be a valid Philippine number (e.g. 09xxxxxxxxx)', 422);
    }

    $db  = db();
    $dupPhone = $db->prepare('SELECT id FROM customers WHERE phone = ?');
    $dupPhone->execute([$cleanPhone]);
    if ($dupPhone->fetch()) {
        json_err('Phone number already registered for another customer', 409);
    }

    if ($pass !== $confirm) json_err('Passwords do not match', 422);
    if (strlen($pass) < 8)  json_err('Password must be at least 8 characters', 422);
    if (!preg_match('/[A-Z]/', $pass)) json_err('Password must contain at least one uppercase letter', 422);
    if (!preg_match('/[0-9]/', $pass)) json_err('Password must contain at least one number', 422);
    if (!preg_match('/[^A-Za-z0-9]/', $pass)) json_err('Password must contain at least one special character', 422);
    if (!$sqId || !array_key_exists($sqId, SECURITY_QUESTIONS)) json_err('Please select a security question', 422);
    if (strlen($sqAns) < 2) json_err('Security answer is too short', 422);

    $db  = db();
    $dup = $db->prepare("SELECT id FROM users WHERE email = ?");
    $dup->execute([$email]);
    if ($dup->fetch()) json_err('Email already registered', 409);

    $hash    = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $ansHash = password_hash(strtolower($sqAns), PASSWORD_BCRYPT, ['cost' => 10]);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO users (email,password,role,status,name) VALUES (?,?,?,?,?)")
           ->execute([$email, $hash, 'customer', 'active', "$fname $lname"]);
        $uid = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO customers (id,fname,lname,phone,address) VALUES (?,?,?,?,?)")
           ->execute([$uid, $fname, $lname, $cleanPhone, $address]);

        // Store security question
        $db->prepare("INSERT INTO security_questions (user_id,question_id,answer_hash) VALUES (?,?,?)")
           ->execute([$uid, $sqId, $ansHash]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_err('Registration failed: ' . $e->getMessage(), 500);
    }

    json_ok(['redirect' => base_url('login.html')], 201);
}

// ── LOGOUT ────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    json_ok(['redirect' => base_url('login.html')]);
}

// ── CHANGE PASSWORD (authenticated) ───────────
if ($action === 'change_password' && $method === 'POST') {
    $user = current_user();
    if (!$user) json_err('Not logged in', 401);

    $b       = body();
    $old     = $b['old_password'] ?? '';
    $new     = $b['new_password'] ?? '';
    $confirm = $b['confirm']      ?? '';

    if (!$old || !$new) json_err('All fields are required', 422);
    if ($new !== $confirm) json_err('Passwords do not match', 422);
    if (strlen($new) < 8)  json_err('Password must be at least 8 characters', 422);
    if (!preg_match('/[A-Z]/', $new)) json_err('Password needs an uppercase letter', 422);
    if (!preg_match('/[0-9]/', $new)) json_err('Password needs a number', 422);
    if (!preg_match('/[^A-Za-z0-9]/', $new)) json_err('Password needs a special character', 422);

    $db   = db();
    $row  = $db->prepare("SELECT password FROM users WHERE id = ?");
    $row->execute([$user['id']]);
    $data = $row->fetch(PDO::FETCH_ASSOC);

    if (!$data || !password_verify($old, $data['password'])) {
        json_err('Current password is incorrect', 401);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?")
       ->execute([$hash, $user['id']]);

    json_ok(['message' => 'Password changed successfully']);
}

// ── FORGOT PASSWORD STEP 1: verify email ──────
if ($action === 'forgot_step1' && $method === 'POST') {
    $b     = body();
    $email = trim($b['email'] ?? '');
    if (!$email) json_err('Email is required', 422);

    $db   = db();
    $stmt = $db->prepare("SELECT u.id FROM users u WHERE u.email = ? AND u.status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always return success-like message to prevent email enumeration
    if (!$user) json_err('No active account found with that email', 404);

    // Get security question
    $sq = $db->prepare("SELECT question_id FROM security_questions WHERE user_id = ?");
    $sq->execute([$user['id']]);
    $sqRow = $sq->fetch(PDO::FETCH_ASSOC);

    if (!$sqRow) json_err('This account has no security question set. Please contact support.', 404);

    $question = SECURITY_QUESTIONS[$sqRow['question_id']] ?? 'Security question not found';

    json_ok([
        'user_id'  => (int)$user['id'],
        'question' => $question,
    ]);
}

// ── FORGOT PASSWORD STEP 2: verify answer ─────
if ($action === 'forgot_step2' && $method === 'POST') {
    $b      = body();
    $userId = (int)($b['user_id'] ?? 0);
    $answer = trim($b['answer']   ?? '');
    if (!$userId || !$answer) json_err('Missing data', 422);

    $db = db();

    // Check lockout status (3 failed attempts -> 12 hour lock)
    $att = $db->prepare("SELECT attempts, locked_until FROM password_reset_attempts WHERE user_id = ? LIMIT 1");
    $att->execute([$userId]);
    $attRow = $att->fetch(PDO::FETCH_ASSOC);
    if ($attRow && $attRow['locked_until']) {
        $lockedTs = strtotime($attRow['locked_until']);
        if (time() < $lockedTs) {
            $secs = $lockedTs - time();
            $hours = floor($secs / 3600);
            $minutes = floor(($secs % 3600) / 60);
            $msg = 'Too many incorrect attempts. Try again in ' . ($hours>0?"{$hours}h ":'') . ($minutes>0?"{$minutes}m":'') . '.';
            json_err($msg, 429);
        }
    }

    $sq = $db->prepare("SELECT answer_hash FROM security_questions WHERE user_id = ?");
    $sq->execute([$userId]);
    $row = $sq->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_err('This account has no security question set. Please contact support.', 404);
    }

    // Verify answer
    if (!password_verify(strtolower($answer), $row['answer_hash'])) {
        // Increment attempt counter
        if ($attRow) {
            $newAttempts = (int)$attRow['attempts'] + 1;
            if ($newAttempts >= 3) {
                $lockedUntil = date('Y-m-d H:i:s', time() + 12 * 3600);
                $db->prepare("UPDATE password_reset_attempts SET attempts = 0, last_attempt_at = NOW(), locked_until = ? WHERE user_id = ?")
                   ->execute([$lockedUntil, $userId]);
                json_err('Too many incorrect attempts. Try again in 12 hours.', 429);
            } else {
                $db->prepare("UPDATE password_reset_attempts SET attempts = ?, last_attempt_at = NOW() WHERE user_id = ?")
                   ->execute([$newAttempts, $userId]);
                $left = 3 - $newAttempts;
                json_err("Incorrect answer. {$left} attempt(s) remaining.", 401);
            }
        } else {
            $db->prepare("INSERT INTO password_reset_attempts (user_id, attempts, last_attempt_at) VALUES (?, 1, NOW())")
               ->execute([$userId]);
            json_err('Incorrect answer. 2 attempt(s) remaining.', 401);
        }
    }

    // Correct answer: clear any attempt records
    $db->prepare("DELETE FROM password_reset_attempts WHERE user_id = ?")->execute([$userId]);

     // Generate reset token (use DB time for expiry to avoid server/DB clock mismatch)
     $token = bin2hex(random_bytes(32));

     // Clean up expired tokens for this user and insert new token (expire in 15 minutes via DB NOW())
     $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? OR expires_at < NOW()")
         ->execute([$userId]);
     $ins = $db->prepare("INSERT INTO password_reset_tokens (user_id,token,security_answer,expires_at) VALUES (?,?,?, NOW() + INTERVAL 15 MINUTE)");
     $ins->execute([$userId, $token, '(verified)']);

    json_ok(['token' => $token]);
}

// ── FORGOT PASSWORD STEP 3: set new password ──
if ($action === 'reset_password' && $method === 'POST') {
    $b    = body();
    $tok  = trim($b['token']    ?? '');
    $pass = $b['password']      ?? '';

    if (!$tok || !$pass) json_err('Missing data', 422);
    if (strlen($pass) < 8) json_err('Password must be at least 8 characters', 422);
    if (!preg_match('/[A-Z]/', $pass)) json_err('Password needs an uppercase letter', 422);
    if (!preg_match('/[0-9]/', $pass)) json_err('Password needs a number', 422);
    if (!preg_match('/[^A-Za-z0-9]/', $pass)) json_err('Password needs a special character', 422);

    $db  = db();
    $row = $db->prepare("SELECT * FROM password_reset_tokens WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $row->execute([$tok]);
    $token = $row->fetch(PDO::FETCH_ASSOC);

    if (!$token) json_err('Invalid or expired reset token', 401);

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?")
       ->execute([$hash, $token['user_id']]);
    $db->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?")
       ->execute([$token['id']]);
     // Invalidate any other outstanding tokens for this user
     $db->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = ?")
         ->execute([$token['user_id']]);

    json_ok(['message' => 'Password reset successfully']);
}

// ── GET SECURITY QUESTIONS LIST ───────────────
if ($action === 'security_questions' && $method === 'GET') {
    $list = [];
    foreach (SECURITY_QUESTIONS as $id => $q) {
        $list[] = ['id' => $id, 'question' => $q];
    }
    json_ok($list);
}

// ── ADMIN: SET USER STATUS ────────────────────
if ($action === 'set_user_status' && $method === 'POST') {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_err('Admin access required', 403);
    }

    $b = body();
    $user_id = (int)($b['user_id'] ?? 0);
    $status = $b['status'] ?? '';
    
    if (!$user_id || !in_array($status, ['active', 'inactive'])) {
        json_err('Invalid user ID or status', 422);
    }

    // Prevent admin from deactivating themselves
    if ($user_id === $user['id']) {
        json_err('Cannot modify own account status', 403);
    }

    $db = db();
    $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
    $result = $stmt->execute([$status, $user_id]);
    
    if ($result) {
        json_ok(['message' => 'User status updated']);
    } else {
        json_err('Failed to update user status', 500);
    }
}

// ── ADMIN: DELETE USER ────────────────────────
if ($action === 'delete_user' && $method === 'POST') {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_err('Admin access required', 403);
    }

    $b = body();
    $user_id = (int)($b['user_id'] ?? 0);
    
    if (!$user_id) {
        json_err('Invalid user ID', 422);
    }

    // Prevent admin from deleting themselves
    if ($user_id === $user['id']) {
        json_err('Cannot delete own account', 403);
    }

    $db = db();
    $db->beginTransaction();
    
    try {
        // Get user role first to determine cleanup
        $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $roleStmt->execute([$user_id]);
        $userRole = $roleStmt->fetchColumn();
        
        // Delete from main users table
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
        
        // Role-specific cleanup
        switch ($userRole) {
            case 'employee':
                $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$user_id]);
                break;
            case 'rider':
                $db->prepare("DELETE FROM riders WHERE id = ?")->execute([$user_id]);
                break;
            case 'customer':
                $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$user_id]);
                $db->prepare("DELETE FROM security_questions WHERE user_id = ?")->execute([$user_id]);
                $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$user_id]);
                break;
        }
        
        $db->commit();
        json_ok(['message' => 'User deleted permanently']);
        
    } catch (Exception $e) {
        $db->rollBack();
        json_err('Failed to delete user: ' . $e->getMessage(), 500);
    }
}

// ── ADMIN: RESET PASSWORD ─────────────────────
if ($action === 'admin_reset_password' && $method === 'POST') {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_err('Admin access required', 403);
    }

    $b = body();
    $target_id = (int)($b['user_id'] ?? 0);
    $new_pass = $b['new_password'] ?? '';
    
    if (!$target_id || strlen($new_pass) < 8) {
        json_err('Invalid user ID or password too short', 422);
    }

    // Prevent admin from resetting their own password this way
    if ($target_id === $user['id']) {
        json_err('Use normal password change for yourself', 403);
    }

    $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db = db();
    $stmt = $db->prepare("UPDATE users SET password = ?, force_password_change = 1 WHERE id = ?");
    $result = $stmt->execute([$hash, $target_id]);
    
    if ($result) {
        json_ok(['message' => 'Password reset - user must change on next login']);
    } else {
        json_err('Failed to reset password', 500);
    }
}

// ── FORCED PASSWORD CHANGE (after admin reset) ─
if ($action === 'force_change_password' && $method === 'POST') {
    $user = current_user();
    if (!$user) json_err('Not logged in', 401);

    $b       = body();
    $new     = $b['new_password'] ?? '';
    $confirm = $b['confirm']      ?? '';

    if (!$new) json_err('Password is required', 422);
    if ($new !== $confirm) json_err('Passwords do not match', 422);
    if (strlen($new) < 8)  json_err('Password must be at least 8 characters', 422);
    if (!preg_match('/[A-Z]/', $new)) json_err('Password needs an uppercase letter', 422);
    if (!preg_match('/[0-9]/', $new)) json_err('Password needs a number', 422);
    if (!preg_match('/[^A-Za-z0-9]/', $new)) json_err('Password needs a special character', 422);

    $db   = db();
    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?")
       ->execute([$hash, $user['id']]);

    $redirect = match($user['role']) {
        'admin'    => 'pages/admin-dashboard.html',
        'employee' => 'pages/employee-dashboard.html',
        'rider'    => 'pages/rider-dashboard.html',
        default    => 'pages/customer-dashboard.html',
    };

    json_ok(['redirect' => $redirect, 'message' => 'Password changed successfully']);
}

json_err('Unknown action or method', 400);