<?php
// =============================================
// METROMART — Shared helpers
// =============================================

// ── Session ───────────────────────────────────
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,   // set true on HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    if (headers_sent() === false) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['ok' => false, 'error' => "Server error: {$errstr}"]);
    exit;
});

set_exception_handler(function ($e) {
    if (headers_sent() === false) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (headers_sent() === false) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode(['ok' => false, 'error' => $err['message']]);
        exit;
    }
});

// ── JSON helpers ──────────────────────────────
function json_ok(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// ── Auth guards ───────────────────────────────
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_auth(array $roles = []): array {
    $user = current_user();
    if (!$user) json_err('Unauthenticated', 401);
    if ($roles && !in_array($user['role'], $roles, true)) json_err('Forbidden', 403);
    return $user;
}

// ── Image upload helper ───────────────────────
// Returns the saved relative path or null on failure.
function save_upload(string $field, string $subdir = 'misc'): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return null;

    $file   = $_FILES[$field];
    $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Allow images and PDFs for documents like licenses
    $allow  = ['jpg','jpeg','png','gif','webp','pdf'];
    if (!in_array($ext, $allow, true)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;   // 5 MB cap for PDFs

    $dir  = __DIR__ . "/../uploads/{$subdir}/";
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $dir . $name);
    return "uploads/{$subdir}/{$name}";
}

require_once __DIR__ . '/upload_helper.php';

// ── Input helpers ─────────────────────────────
function body() {
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (is_array($data)) return $data;
    return $_POST;
}

function param(string $key, mixed $default = null): mixed {
    return $_REQUEST[$key] ?? $default;
}

function normalizePHMobile(string $raw): string {
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '63') {
        $digits = substr($digits, 2);
    }
    return $digits;
}

function isValidPHMobile(string $raw): bool {
    return preg_match('/^9\d{9}$/', normalizePHMobile($raw)) === 1;
}

function isPhoneTaken($db, string $rawPhone, array $exclude = []): bool {
    $phone = normalizePHMobile($rawPhone);
    if (!$phone) return false;

    $tables = [
        ['customers', 'phone'],
        ['employees', 'phone'],
        ['riders', 'phone'],
        ['merchants', 'contact'],
    ];

    foreach ($tables as $pair) {
        [$table, $field] = $pair;
        $rows = $db->query("SELECT id, {$field} FROM {$table}")->fetchAll();
        foreach ($rows as $row) {
            if (isset($exclude[$table]) && (int)$exclude[$table] === (int)$row['id']) {
                continue;
            }
            if (!empty($row[$field]) && normalizePHMobile((string)$row[$field]) === $phone) {
                return true;
            }
        }
    }

    return false;
}