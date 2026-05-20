<?php
// =============================================
// METROMART — Products API
// File: api/products/index.php
//
// GET  ?merchant_id=N&category=X&limit=50  → list (public, Available only)
// GET  ?id=N                               → single product
// GET  ?all=1&merchant_id=N               → all products for employee
// POST (multipart)                         → create / update (admin/employee)
// DELETE ?id=N                             → delete (admin/employee)
// =============================================
ob_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method     = $_SERVER['REQUEST_METHOD'];
$id         = (int) ($_GET['id']          ?? param('id', 0));
$merchantId = (int) ($_GET['merchant_id'] ?? param('merchant_id', 0));
$category   = trim($_GET['category']      ?? param('category', ''));
$limit      = max(1, min(200, (int) ($_GET['limit'] ?? param('limit', 50))));
$showAll    = isset($_GET['all']) || param('all', false);

switch ($method) {

    case 'GET':
        $db = db();
        if ($id) {
            $s = $db->prepare('SELECT p.*, m.name AS merchant_name
                               FROM products p JOIN merchants m ON m.id=p.merchant_id
                               WHERE p.id=?');
            $s->execute([$id]);
            $row = $s->fetch();
            if (!$row) json_err('Not found', 404);
            json_ok($row);
        }

        $where  = [];
        $params = [];

        // Employees/admins can see all statuses for their store
        if (!$showAll) {
            $where[]  = 'p.status = "Available"';
            
            // If customer is authenticated, exclude their own store's products
            $user = current_user();
            if ($user && $user['role'] === 'customer') {
                // Check if this customer is also an employee
                $empStmt = $db->prepare('SELECT merchant_id FROM employees WHERE id = ?');
                $empStmt->execute([$user['id']]);
                $empMerchantId = $empStmt->fetchColumn();
                
                if ($empMerchantId) {
                    // Customer is also an employee - exclude their store's products
                    $where[] = 'p.merchant_id != ?';
                    $params[] = $empMerchantId;
                }
            }
        }
        if ($merchantId) { $where[] = 'p.merchant_id = ?'; $params[] = $merchantId; }
        if ($category)   {
            // support filtering by slug against single category_id or JSON array category_ids
            $where[] = '(p.category_id = ? OR (p.category_ids IS NOT NULL AND JSON_CONTAINS(p.category_ids, JSON_QUOTE(?))))';
            $params[] = $category; $params[] = $category;
        }

        $cond = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql  = "SELECT p.*, m.name AS merchant_name
                 FROM products p
                 JOIN merchants m ON m.id = p.merchant_id
                 {$cond}
                 ORDER BY p.created_at DESC
                 LIMIT {$limit}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_ok($stmt->fetchAll());

    case 'POST':
        require_auth(['admin', 'employee']);
        $name    = trim($_POST['name']        ?? param('name',        ''));
        $desc    = trim($_POST['description'] ?? param('description', ''));
        $price   = (float)($_POST['price']    ?? param('price',       0));
        $qty     = (int)($_POST['qty']        ?? param('qty',         0));
        $catSlug = $_POST['category_id']      ?? param('category_id', 'grocery');
        $mId     = (int)($_POST['merchant_id'] ?? param('merchant_id', 0));

        if (!$name || $price <= 0 || !$mId) {
            json_err('name, price > 0, and merchant_id are required');
        }

        $img = null;
        if (!empty($_FILES['image']['tmp_name'])) {
            $img = save_upload('image', 'products');
            if (!$img) {
                json_err('Image upload failed. Allowed types: jpg, jpeg, png, gif, webp, and max size 2MB.', 400);
            }
        }
        $db  = db();

        // Normalize incoming category(s). Frontend may send a single slug or comma-separated slugs.
        $cats = array_filter(array_map('trim', explode(',', (string)$catSlug)));
        $primaryCat = $cats[0] ?? null;
        $catsJson = $cats ? json_encode(array_values($cats), JSON_UNESCAPED_UNICODE) : null;

        if ($id) {
            // UPDATE
            if ($img) {
                $db->prepare('UPDATE products SET name=?,description=?,price=?,qty=?,category_id=?,category_ids=?,image_path=?,updated_at=NOW() WHERE id=?')
                   ->execute([$name, $desc, $price, $qty, $primaryCat, $catsJson, $img, $id]);
            } else {
                $db->prepare('UPDATE products SET name=?,description=?,price=?,qty=?,category_id=?,category_ids=?,updated_at=NOW() WHERE id=?')
                   ->execute([$name, $desc, $price, $qty, $primaryCat, $catsJson, $id]);
            }
            json_ok();
        } else {
            $db->prepare('INSERT INTO products (merchant_id,category_id,category_ids,name,description,price,qty,image_path) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$mId, $primaryCat, $catsJson, $name, $desc, $price, $qty, $img]);
            json_ok(['id' => (int) $db->lastInsertId()], 201);
        }

    case 'DELETE':
        require_auth(['admin', 'employee']);
        if (!$id) json_err('id required');
        db()->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}