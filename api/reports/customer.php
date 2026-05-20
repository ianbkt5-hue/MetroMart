<?php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = db();
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['order_id']) || !isset($input['reason'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing required fields: order_id, reason']);
        exit;
    }

    $user = require_auth(['rider']);
    $pdo = db();
    $rider_id = $user['id'];

    $order_id = (int)$input['order_id'];
    $customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $reason = trim($input['reason']);
    $details = isset($input['details']) ? trim($input['details']) : null;

    // Validate reason
    $valid_reasons = ['fake_address', 'no_answer', 'refused_delivery', 'fraud', 'other'];
    if (!in_array($reason, $valid_reasons)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid reason']);
        exit;
    }

    // Check if rider already has a pending report for this order (prevent spam)
    $check_stmt = $pdo->prepare("
        SELECT id FROM customer_reports 
        WHERE reporter_id = ? AND order_id = ? AND status = 'pending'
    ");
    $check_stmt->execute([$rider_id, $order_id]);
    
    if ($check_stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'You already have a pending report for this order']);
        exit;
    }

    // Insert report
    $stmt = $pdo->prepare("
        INSERT INTO customer_reports (reporter_id, order_id, customer_id, reason, details) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $success = $stmt->execute([
        $rider_id,
        $order_id,
        $customer_id,
        $reason,
        $details
    ]);

    if ($success) {
        $report_id = $pdo->lastInsertId();
        
        // Optional: Log admin notification
        logAdminNotification($pdo, $rider_id, $order_id, $reason);
        
        echo json_encode([
            'ok' => true,
            'message' => 'Report submitted successfully',
            'data' => [
                'report_id' => $report_id,
                'status' => 'pending'
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save report']);
    }

} catch (Exception $e) {
    error_log("Report API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}

function logAdminNotification($pdo, $rider_id, $order_id, $reason) {
    try {
        $reason_labels = [
            'fake_address' => 'Fake address',
            'no_answer' => 'No answer', 
            'refused_delivery' => 'Refused delivery',
            'fraud' => 'Suspected fraud',
            'other' => 'Other issue'
        ];
        
        $message = "Rider reported order #{$order_id}: " . ($reason_labels[$reason] ?? $reason);
        
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO admin_notifications (type, rider_id, order_id, message) 
            VALUES ('rider_report', ?, ?, ?)
        ");
        $stmt->execute([$rider_id, $order_id, $message]);
    } catch (Exception $e) {
        error_log("Notification log failed: " . $e->getMessage());
    }
}
?>