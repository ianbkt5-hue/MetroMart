<?php
// =============================================
// METROMART — Customer Report API (filed by rider)
// api/reports/customer.php
//
// POST { order_id, customer_id, reason, details } → create report
// =============================================

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$user = require_auth(['rider']);
$db   = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$b          = body();
$orderId    = (int)($b['order_id']    ?? 0);
$customerId = (int)($b['customer_id'] ?? 0);
$reason     = trim($b['reason']       ?? '');
$details    = trim($b['details']      ?? '');

$validReasons = ['fake_address','no_answer','refused_delivery','fraud','other'];
if (!$orderId || !$customerId || !in_array($reason, $validReasons, true)) {
    json_err('Missing or invalid fields', 422);
}

// Verify the order exists and belongs to this rider
$order = $db->prepare("SELECT * FROM orders WHERE id = ? AND rider_id = ? LIMIT 1");
$order->execute([$orderId, $user['id']]);
if (!$order->fetch()) json_err('Order not found or not assigned to you', 403);

// Check if already reported for this order
$dup = $db->prepare("SELECT id FROM customer_reports WHERE reporter_id = ? AND order_id = ?");
$dup->execute([$user['id'], $orderId]);
if ($dup->fetch()) json_err('You have already filed a report for this order', 409);

$db->prepare("
    INSERT INTO customer_reports (reporter_id, customer_id, order_id, reason, details, status)
    VALUES (?,?,?,?,?,'pending')
")->execute([$user['id'], $customerId, $orderId, $reason, $details]);

// Notify admin(s)
$adminIds = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(\PDO::FETCH_COLUMN);
$notif    = $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)");
foreach ($adminIds as $adminId) {
    $notif->execute([
        $adminId,
        'report_filed',
        'New Rider Report Filed',
        "Rider #{$user['id']} reported customer #{$customerId} for order #{$orderId}: {$reason}"
    ]);
}

json_ok(['message' => 'Report submitted. Admin will review it shortly.'], 201);