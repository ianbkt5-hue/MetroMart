<?php
// =============================================
// METROMART — Admin Reports API
// api/admin/reports.php
//
// GET              → list customer reports (admin)
// POST {report_id, status} → update report status (admin)
// =============================================

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

require_auth(['admin', 'employee']);
$db = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Fetch customer_reports joined with rider + customer info
    $rows = $db->query("
        SELECT
            cr.id,
            cr.reason,
            cr.details,
            cr.customer_reply,
            cr.customer_replied_at,
            cr.status,
            cr.order_id,
            cr.created_at,
            CONCAT(r.fname,' ',r.lname) AS reporter_name,
            cr.reporter_id,
            CONCAT(c.fname,' ',c.lname) AS customer_name,
            cr.customer_id,
            cu.email AS customer_email,
            cu.status AS customer_account_status
        FROM customer_reports cr
        JOIN riders r  ON r.id  = cr.reporter_id
        JOIN customers c ON c.id = cr.customer_id
        JOIN users cu ON cu.id = cr.customer_id
        ORDER BY
            CASE cr.status WHEN 'pending' THEN 0 WHEN 'reviewed' THEN 1 ELSE 2 END,
            cr.created_at DESC
        LIMIT 200
    ")->fetchAll();
    json_ok($rows);
}

if ($method === 'POST') {
    $b = body();
    $reportId = (int)($b['report_id'] ?? 0);
    $status   = $b['status'] ?? '';

    if (!$reportId || !in_array($status, ['reviewed','dismissed'], true)) {
        json_err('Invalid request', 422);
    }

    $db->prepare("UPDATE customer_reports SET status = ? WHERE id = ?")
       ->execute([$status, $reportId]);

    json_ok();
}

json_err('Method not allowed', 405);