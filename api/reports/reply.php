<?php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    json_err('Method not allowed', 405);
}

$user = require_auth(['customer']);
$b    = body();
$reportId = (int)($b['report_id'] ?? 0);
$reply    = trim($b['reply'] ?? '');

if (!$reportId || $reply === '') {
    json_err('report_id and reply are required', 422);
}

$db = db();
$stmt = $db->prepare('SELECT * FROM customer_reports WHERE id = ? AND customer_id = ? LIMIT 1');
$stmt->execute([$reportId, $user['id']]);
$report = $stmt->fetch();
if (!$report) {
    json_err('Report not found', 404);
}

$update = $db->prepare('UPDATE customer_reports SET customer_reply = ?, customer_replied_at = NOW() WHERE id = ?');
$update->execute([$reply, $reportId]);

// Notify admins that the customer replied to a rider report
$adminIds = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
$notif = $db->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)');
foreach ($adminIds as $adminId) {
    $notif->execute([
        $adminId,
        'report_filed',
        'Customer replied to rider report',
        'Customer #' . $user['id'] . ' replied to report #' . $reportId . ' for order #' . str_pad($report['order_id'], 6, '0', STR_PAD_LEFT) . '.'
    ]);
}

json_ok(['message' => 'Reply saved successfully']);
