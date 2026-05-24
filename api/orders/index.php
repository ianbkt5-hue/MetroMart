<?php
// api/orders/index.php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id'] ?? param('id', 0));

switch ($method) {

    case 'GET':
        $user = require_auth();
        $db   = db();

        // Helper function to safely select columns with fallback
        $selectReportColumns = function() {
            // Try to detect if customer_reply columns exist
            try {
                return "cr.id AS report_id,
                        cr.reason AS report_reason,
                        cr.details AS report_details,
                        cr.status AS report_status,
                        COALESCE(cr.customer_reply, NULL) AS report_customer_reply,
                        COALESCE(cr.customer_replied_at, NULL) AS report_customer_replied_at";
            } catch (Exception $e) {
                return "cr.id AS report_id,
                        cr.reason AS report_reason,
                        cr.details AS report_details,
                        cr.status AS report_status,
                        NULL AS report_customer_reply,
                        NULL AS report_customer_replied_at";
            }
        };

        if ($user['role'] === 'customer') {
            $s = $db->prepare(
                'SELECT o.*, m.name AS merchant_name,
                        CONCAT(COALESCE(r.fname, ""), " ", COALESCE(r.lname, "")) AS rider_name,
                        r.phone AS rider_phone,
                        r.vehicle_type AS rider_vehicle_type,
                        cr.id AS report_id,
                        cr.reason AS report_reason,
                        cr.details AS report_details,
                        cr.status AS report_status,
                        NULL AS report_customer_reply,
                        NULL AS report_customer_replied_at
                 FROM orders o
                 JOIN merchants m ON m.id = o.merchant_id
                 LEFT JOIN riders r ON r.id = o.rider_id
                 LEFT JOIN customer_reports cr ON cr.id = (
                     SELECT id FROM customer_reports WHERE order_id = o.id ORDER BY created_at DESC LIMIT 1
                 )
                 WHERE o.customer_id = ?
                 ORDER BY o.ordered_at DESC LIMIT 50'
            );
            $s->execute([$user['id']]);

        } elseif ($user['role'] === 'employee') {
            $mStmt = $db->prepare('SELECT merchant_id FROM employees WHERE id = ?');
            $mStmt->execute([$user['id']]);
            $mId = $mStmt->fetchColumn();
            if (!$mId) json_ok([]);
            $s = $db->prepare(
                'SELECT DISTINCT o.*,
                        CONCAT(c.fname," ",c.lname) AS customer_name,
                        c.phone AS customer_phone,
                        cr.id AS report_id,
                        cr.reason AS report_reason,
                        cr.details AS report_details,
                        cr.status AS report_status,
                        NULL AS report_customer_reply,
                        NULL AS report_customer_replied_at
                 FROM orders o
                 JOIN customers c ON c.id = o.customer_id
                 JOIN order_details od ON od.order_id = o.id
                 JOIN products p ON p.id = od.product_id
                 LEFT JOIN customer_reports cr ON cr.id = (
                     SELECT id FROM customer_reports WHERE order_id = o.id ORDER BY created_at DESC LIMIT 1
                 )
                 WHERE p.merchant_id = ?
                 ORDER BY o.ordered_at DESC LIMIT 100'
            );
            $s->execute([(int)$mId]);

        } elseif ($user['role'] === 'rider') {
            // Only show available deliveries when the rider is online/busy, not when offline.
            $s = $db->prepare(
                "SELECT o.*,
                        CONCAT(c.fname,' ',c.lname) AS customer_name,
                        c.phone AS customer_phone,
                        c.address AS customer_address,
                        cr.id AS report_id,
                        cr.reason AS report_reason,
                        cr.details AS report_details,
                        cr.status AS report_status,
                        NULL AS report_customer_reply,
                        NULL AS report_customer_replied_at
                 FROM orders o
                 JOIN customers c ON c.id = o.customer_id
                 LEFT JOIN customer_reports cr ON cr.id = (
                     SELECT id FROM customer_reports WHERE order_id = o.id ORDER BY created_at DESC LIMIT 1
                 )
                 WHERE (o.status = 'Ready for Delivery' AND (
                        SELECT COALESCE(rider_status, 'offline') FROM riders WHERE id = ?
                 ) != 'offline')
                 OR o.rider_id = ?
                 ORDER BY o.ordered_at DESC LIMIT 100"
            );
            $s->execute([$user['id'], $user['id']]);

        } else {
            // admin
            $s = $db->query(
                'SELECT o.*,
                        CONCAT(c.fname," ",c.lname) AS customer_name,
                        m.name AS merchant_name,
                        cr.id AS report_id,
                        cr.reason AS report_reason,
                        cr.details AS report_details,
                        cr.status AS report_status,
                        NULL AS report_customer_reply,
                        NULL AS report_customer_replied_at
                 FROM orders o
                 JOIN customers c ON c.id = o.customer_id
                 JOIN merchants m ON m.id = o.merchant_id
                 LEFT JOIN customer_reports cr ON cr.id = (
                     SELECT id FROM customer_reports WHERE order_id = o.id ORDER BY created_at DESC LIMIT 1
                 )
                 ORDER BY o.ordered_at DESC LIMIT 200'
            );
        }

        $orders = $s->fetchAll();

        // Attach items to each order
        // For employees, only show products from their store
        $isEmployee = $user['role'] === 'employee';
        $employeeMerchantId = null;
        if ($isEmployee) {
            $mStmt = $db->prepare('SELECT merchant_id FROM employees WHERE id = ?');
            $mStmt->execute([$user['id']]);
            $employeeMerchantId = $mStmt->fetchColumn();
        }
        
        if ($isEmployee && $employeeMerchantId) {
            // For employees, only show products that belong to their store
            $d = $db->prepare(
                'SELECT od.*, p.name AS product_name, p.image_path, p.merchant_id
                 FROM order_details od
                 JOIN products p ON p.id = od.product_id
                 WHERE od.order_id = ? AND p.merchant_id = ?'
            );
        } else {
            // For customers, riders, and admins, show all products
            $d = $db->prepare(
                'SELECT od.*, p.name AS product_name, p.image_path
                 FROM order_details od
                 JOIN products p ON p.id = od.product_id
                 WHERE od.order_id = ?'
            );
        }
        
        foreach ($orders as &$order) {
            if ($isEmployee && $employeeMerchantId) {
                $d->execute([$order['id'], $employeeMerchantId]);
            } else {
                $d->execute([$order['id']]);
            }
            $order['items'] = $d->fetchAll();
            if ($isEmployee) {
                $productTotal = 0;
                foreach ($order['items'] as $item) {
                    $lineTotal = isset($item['subtotal']) ? (float)$item['subtotal'] : ((float)$item['unit_price'] * (int)$item['qty']);
                    $productTotal += $lineTotal;
                }
                $order['total'] = $productTotal;
            }
        }
        json_ok($orders);

    case 'POST':
        $user = require_auth(['customer']);
        $b    = body();
        $db   = db();

        $items     = $b['items']      ?? [];
        $address   = trim($b['address']     ?? '');
        $payMethod = $b['pay_method']       ?? 'cod';
        $lat       = $b['lat']              ?? null;
        $lng       = $b['lng']              ?? null;
        $tipAmount = (float)($b['tip_amount'] ?? 0);

        if (empty($items) || !$address) json_err('items and address are required');

        // Check whether this customer is an employee with a store
        $empStmt = $db->prepare('SELECT merchant_id FROM employees WHERE id = ?');
        $empStmt->execute([$user['id']]);
        $customerMerchantId = $empStmt->fetchColumn();

        $orderGroups = [];
        foreach ($items as $item) {
            $ps2 = $db->prepare('SELECT id, price, merchant_id FROM products WHERE id=? AND status="Available"');
            $ps2->execute([$item['product_id']]);
            $prod = $ps2->fetch();
            if (!$prod) json_err("Product {$item['product_id']} not available");
            if ($customerMerchantId && $prod['merchant_id'] == $customerMerchantId) {
                json_err('You cannot purchase products from your own store', 400);
            }

            $qty = max(1, (int)($item['qty'] ?? 1));
            $merchantId = $prod['merchant_id'];
            if (!isset($orderGroups[$merchantId])) {
                $orderGroups[$merchantId] = ['items' => [], 'total' => 0];
            }
            $orderGroups[$merchantId]['items'][] = ['product_id' => $prod['id'], 'qty' => $qty, 'unit_price' => $prod['price']];
            $orderGroups[$merchantId]['total'] += $prod['price'] * $qty;
        }

        if (empty($orderGroups)) json_err('No valid items to order');

        $deliveryFeePerStore = 50.00;
        $storeCount = count($orderGroups);
        $tipPerStore = round($tipAmount / $storeCount, 2);
        $remainingTip = $tipAmount;

        $createdOrders = [];
        $db->beginTransaction();
        try {
            foreach ($orderGroups as $merchantId => $group) {
                $tipForOrder = $storeCount === 1 ? $tipAmount : $tipPerStore;
                if ($storeCount > 1) {
                    $storeCount -= 1;
                    if ($storeCount === 0) {
                        $tipForOrder = $remainingTip;
                    } else {
                        $remainingTip -= $tipForOrder;
                    }
                }

                $grandTotal = $group['total'] + $deliveryFeePerStore + $tipForOrder;
                $db->prepare(
                    'INSERT INTO orders
                     (customer_id,merchant_id,status,total,delivery_fee,tip_amount,pay_method,pay_status,delivery_address,delivery_lat,delivery_lng)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $user['id'], $merchantId, 'Pending', $group['total'], $deliveryFeePerStore, $tipForOrder, $payMethod,
                    $payMethod === 'cod' ? 'Pending' : 'Paid',
                    $address, $lat, $lng
                ]);
                $orderId = (int) $db->lastInsertId();
                $createdOrders[] = $orderId;

                $ins = $db->prepare(
                    'INSERT INTO order_details (order_id,product_id,qty,unit_price) VALUES (?,?,?,?)'
                );
                foreach ($group['items'] as $r) {
                    $ins->execute([$orderId, $r['product_id'], $r['qty'], $r['unit_price']]);
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json_err('Order failed: ' . $e->getMessage(), 500);
        }
        json_ok(['order_ids' => $createdOrders], 201);

    case 'PATCH':
    case 'PUT':
        $user = require_auth(['admin', 'employee', 'rider', 'customer']);
        if (!$id) json_err('id required');
        $b             = body();
        $status        = $b['status'] ?? param('status', '');
        $cancelItemId  = isset($b['cancel_item_id']) ? (int)$b['cancel_item_id'] : null;
        $tipAmount     = isset($b['tip_amount']) ? (float)$b['tip_amount'] : null;
        $isReport      = isset($b['is_report']) ? filter_var($b['is_report'], FILTER_VALIDATE_BOOLEAN) : false;
        $db            = db();

        // Check if order exists
        $oStmt = $db->prepare('SELECT * FROM orders WHERE id=?');
        $oStmt->execute([$id]);
        $order = $oStmt->fetch();
        if (!$order) json_err('Order not found', 404);

        // For customers: must be their own order
        if ($user['role'] === 'customer' && $order['customer_id'] != $user['id']) {
            json_err('Access denied', 403);
        }

        if ($cancelItemId !== null) {
            if ($user['role'] !== 'customer') {
                json_err('Only customers can cancel items', 403);
            }
            if ($order['status'] !== 'Pending') {
                json_err('Only pending orders may be modified', 400);
            }

            $detStmt = $db->prepare('SELECT id, subtotal FROM order_details WHERE id=? AND order_id=?');
            $detStmt->execute([$cancelItemId, $id]);
            $detail = $detStmt->fetch();
            if (!$detail) {
                json_err('Order item not found', 404);
            }

            $db->beginTransaction();
            try {
                // Mark item as Cancelled instead of deleting
                $db->prepare('UPDATE order_details SET status=? WHERE id=?')->execute(['Cancelled', $cancelItemId]);
                
                // Calculate totals only from Active items
                $remaining = $db->prepare('SELECT COALESCE(SUM(subtotal),0) AS total, COUNT(*) AS count FROM order_details WHERE order_id=? AND status=?');
                $remaining->execute([$id, 'Active']);
                $row = $remaining->fetch();

                          if ((int)$row['count'] === 0) {
                          $db->prepare('UPDATE orders SET status=?, total=0, delivery_fee=0 WHERE id=?')
                              ->execute(['Cancelled', $id]);
                     } else {
                          $newTotal = round((float)$row['total'], 2);
                          $db->prepare('UPDATE orders SET total=? WHERE id=?')
                              ->execute([$newTotal, $id]);
                     }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                json_err('Cancelling item failed: ' . $e->getMessage(), 500);
            }

            $oStmt->execute([$id]);
            $updatedOrder = $oStmt->fetch();
            json_ok($updatedOrder);
        }

        $fields = [];
        $vals   = [];
        $arrived = isset($b['arrived']) ? filter_var($b['arrived'], FILTER_VALIDATE_BOOLEAN) : false;
        $arrivedNotified = false;

        // Handle STATUS changes
        $allowed = [
            'admin'    => ['Pending','Ready for Delivery','Delivering','Delivered','Cancelled'],
            'employee' => ['Ready for Delivery','Cancelled'],
            'rider'    => ['Delivering','Delivered'],
            'customer' => ['Cancelled'],
        ];

        // Allow riders to cancel via report
        if ($isReport && $user['role'] === 'rider' && $status === 'Cancelled') {
            $allowed['rider'][] = 'Cancelled';
        }

        if ($status && in_array($status, $allowed[$user['role']] ?? [], true)) {
            $fields[] = 'status = ?';
            $vals[]   = $status;

            // Assign rider when they accept the delivery
            if ($status === 'Delivering' && $user['role'] === 'rider') {
                $fields[] = 'rider_id = ?';
                $vals[]   = $user['id'];
            }

            // Deduct stock when order moves to Delivering
            if ($status === 'Delivering' && !$order['stock_deducted']) {
                $dets = $db->prepare('SELECT product_id, qty FROM order_details WHERE order_id=?');
                $dets->execute([$id]);
                foreach ($dets->fetchAll() as $det) {
                    $db->prepare('UPDATE products SET qty = GREATEST(0, qty - ?) WHERE id=?')
                       ->execute([$det['qty'], $det['product_id']]);
                }
                $fields[] = 'stock_deducted = 1';
            }

            // Mark delivered_at timestamp when delivered
            if ($status === 'Delivered') {
                $fields[] = 'delivered_at = NOW()';
                if ($order['status'] !== 'Delivered' && $order['rider_id']) {
                          // Only credit the base delivery fee for cashless payments.
                          // Tips are handled separately in the tip update block below.
                          $baseFee = 50.00;
                          $payMethod = $order['pay_method'] ?? 'cod';
                          if (strtolower($payMethod) !== 'cod') {
                                $db->prepare('UPDATE riders SET wallet_balance = wallet_balance + ? WHERE id = ?')
                                    ->execute([$baseFee, $order['rider_id']]);
                                $db->prepare('INSERT INTO rider_wallet_log (rider_id, order_id, amount, type, description) VALUES (?,?,?,?,?)')
                                    ->execute([$order['rider_id'], $id, $baseFee, 'delivery_fee', 'Delivery earnings for order #' . $id]);
                          }
                }
            }
        }

        // Handle report-based cancellation (mark for reversal later in main transaction)
        $shouldReverseDeliveryFee = $status === 'Cancelled' && $isReport && $user['role'] === 'rider' && $order['status'] === 'Delivered' && $order['rider_id'];

        if ($status === 'Cancelled' && $isReport && $user['role'] === 'rider') {
            $notif = $db->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)');
            $notif->execute([
                $order['customer_id'],
                'order_update',
                'Order cancelled by rider report',
                'Your order #' . str_pad($id, 6, '0', STR_PAD_LEFT) . ' was cancelled by the rider and flagged for review. Admin will review the report shortly.'
            ]);
        }

        if ($arrived && $user['role'] === 'rider' && $order['rider_id'] == $user['id'] && $order['status'] === 'Delivering') {
            $fields[] = 'arrived_at = NOW()';
            $notif = $db->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)');
            $notif->execute([
                $order['customer_id'],
                'order_update',
                'Rider has arrived',
                'Your rider has arrived for order #' . str_pad($id, 6, '0', STR_PAD_LEFT) . '.'
            ]);
            $arrivedNotified = true;
        }

        // Handle TIP updates - Allow customers to add tips to Delivered orders
        if ($tipAmount !== null) {
            // Only allow tips on Delivered orders
            if ($order['status'] !== 'Delivered') {
                json_err('Tips can only be added to delivered orders', 400);
            }
            $fields[] = 'tip_amount = ?';
            $vals[]   = $tipAmount;
        }

        // If no valid updates, either return the original order (arrival notification only)
        if (empty($fields)) {
            if ($arrivedNotified) {
                json_ok($order);
            }
            json_err('No valid updates provided', 400);
        }

        // Build and execute update inside a transaction so wallet updates are atomic
        $vals[] = $id;  // WHERE id = ?
        $set    = implode(', ', $fields);
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE orders SET {$set} WHERE id=?")->execute($vals);

            // If this is a report-based cancellation, reverse the delivery fee
            if ($shouldReverseDeliveryFee) {
                // Reverse only what was previously credited: base fee for cashless orders, plus any tip amount.
                $baseFee = 50.00;
                $payMethod = $order['pay_method'] ?? 'cod';
                $reversalAmount = 0.0;
                if (strtolower($payMethod) !== 'cod') {
                    $reversalAmount += $baseFee;
                }
                $reversalAmount += max(0, floatval($order['tip_amount'] ?? 0));

                if ($reversalAmount != 0 && $order['rider_id']) {
                    $db->prepare('UPDATE riders SET wallet_balance = wallet_balance - ? WHERE id = ?')
                       ->execute([$reversalAmount, $order['rider_id']]);
                    $db->prepare('INSERT INTO rider_wallet_log (rider_id, order_id, amount, type, description) VALUES (?,?,?,?,?)')
                       ->execute([$order['rider_id'], $id, -$reversalAmount, 'reversal', 'Delivery fee reversed - order #' . $id . ' reported and cancelled']);
                }
            }

            // If a tip was provided, credit the rider only for the positive delta
            if ($tipAmount !== null) {
                $oldTip = isset($order['tip_amount']) ? floatval($order['tip_amount']) : 0.0;
                $delta = round($tipAmount - $oldTip, 2);
                if ($delta > 0 && $order['rider_id']) {
                    $db->prepare('UPDATE riders SET wallet_balance = wallet_balance + ? WHERE id = ?')
                       ->execute([$delta, $order['rider_id']]);
                    $db->prepare('INSERT INTO rider_wallet_log (rider_id, order_id, amount, type, description) VALUES (?,?,?,?,?)')
                       ->execute([$order['rider_id'], $id, $delta, 'tip', 'Tip for order #' . $id]);
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json_err('Update failed: ' . $e->getMessage(), 500);
        }

        // Return updated order
        $oStmt->execute([$id]);
        $updatedOrder = $oStmt->fetch();
        json_ok($updatedOrder);

    case 'DELETE':
        require_auth(['admin', 'employee']);
        if (!$id) json_err('id required');
        db()->prepare('DELETE FROM orders WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}