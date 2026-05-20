<?php
// =============================================
// METROMART — Auth API Additional Actions v2
// APPEND these handlers to api/auth/index.php
// (paste BEFORE the final json_err fallback line)
// =============================================

// ── SET USER STATUS (admin: activate / deactivate) ─────────────────────────
// POST { user_id, status: 'active' | 'inactive' }
// Inactive users cannot log in — login query filters status='active'
if ($action === 'set_user_status' && $method === 'POST') {
    require_auth(['admin']);
    $b      = body();
    $uid    = (int)($b['user_id'] ?? 0);
    $status = $b['status'] ?? '';

    if (!$uid || !in_array($status, ['active', 'inactive'], true)) {
        json_err('user_id and valid status (active|inactive) are required', 422);
    }

    $stmt = db()->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$status, $uid]);

    if ($stmt->rowCount() === 0) {
        json_err('User not found', 404);
    }

    json_ok(['message' => 'Status updated to ' . $status]);
}

// ── DELETE USER PERMANENTLY (admin) ────────────────────────────────────────
// POST { user_id }
// Cascades through foreign keys to remove customer/employee/rider rows too.
if ($action === 'delete_user' && $method === 'POST') {
    require_auth(['admin']);
    $b   = body();
    $uid = (int)($b['user_id'] ?? 0);

    if (!$uid) json_err('user_id is required', 422);

    // Prevent self-deletion
    $me = current_user();
    if ($me && (int)$me['id'] === $uid) {
        json_err('You cannot delete your own account', 403);
    }

    $stmt = db()->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$uid]);

    if ($stmt->rowCount() === 0) {
        json_err('User not found', 404);
    }

    json_ok(['message' => 'User permanently deleted']);
}

// ── FORCE CHANGE PASSWORD (first login for employee / rider) ───────────────
// POST { new_password, confirm }
if ($action === 'force_change_password' && $method === 'POST') {
    $user = current_user();
    if (!$user) json_err('Not logged in', 401);

    $b       = body();
    $new     = $b['new_password'] ?? '';
    $confirm = $b['confirm']      ?? '';

    if (!$new || !$confirm)  json_err('All fields are required', 422);
    if ($new !== $confirm)   json_err('Passwords do not match', 422);
    if (strlen($new) < 8)    json_err('Password must be at least 8 characters', 422);

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?")
       ->execute([$hash, $user['id']]);

    $redirect = match($user['role']) {
        'rider'    => base_url('pages/rider-dashboard.html'),
        'employee' => base_url('pages/employee-dashboard.html'),
        default    => base_url('pages/customer-dashboard.html'),
    };

    json_ok(['redirect' => $redirect, 'message' => 'Password changed successfully']);
}

// ── ADMIN RESET PASSWORD ────────────────────────────────────────────────────
// POST { user_id, new_password }
if ($action === 'admin_reset_password' && $method === 'POST') {
    require_auth(['admin']);
    $b   = body();
    $uid = (int)($b['user_id'] ?? 0);
    $new = $b['new_password'] ?? '';

    if (!$uid || !$new)   json_err('user_id and new_password are required', 422);
    if (strlen($new) < 8) json_err('Password must be at least 8 characters', 422);

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = db()->prepare("UPDATE users SET password = ?, force_password_change = 1 WHERE id = ?");
    $stmt->execute([$hash, $uid]);

    if ($stmt->rowCount() === 0) json_err('User not found', 404);

    json_ok(['message' => 'Password reset. User must change it on next login.']);
}

// ── CHANGE PASSWORD (authenticated user) ────────────────────────────────────
// POST { old_password, new_password, confirm }
if ($action === 'change_password' && $method === 'POST') {
    $user = current_user();
    if (!$user) json_err('Not logged in', 401);

    $b    = body();
    $old  = $b['old_password'] ?? '';
    $new  = $b['new_password'] ?? '';
    $conf = $b['confirm']      ?? '';

    if (!$old || !$new) json_err('All fields are required', 422);
    if ($new !== $conf) json_err('Passwords do not match', 422);
    if (strlen($new) < 8) json_err('Password must be at least 8 characters', 422);

    $db  = db();
    $row = $db->prepare("SELECT password FROM users WHERE id = ?");
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