<?php
// api/customers/index.php
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth(['admin', 'customer']);
$id     = (int) ($_GET['id'] ?? param('id', 0));

switch ($method) {

    case 'GET':
        if ($user['role'] === 'admin') {
            $rows = db()->query(
                'SELECT u.id, u.email, u.status, c.fname, c.lname, c.phone, c.address, c.created_at
                 FROM customers c JOIN users u ON u.id = c.id
                 ORDER BY c.fname'
            )->fetchAll();
            json_ok($rows);
        }
        $s = db()->prepare(
            'SELECT u.email, u.status, c.* FROM customers c JOIN users u ON u.id=c.id WHERE c.id=?'
        );
        $s->execute([$user['id']]);
        json_ok($s->fetch());

    case 'POST':
    case 'PUT':
        $targetId = ($user['role'] === 'admin' && $id) ? $id : $user['id'];
        if ($user['role'] !== 'admin' && $user['id'] !== $targetId) json_err('Forbidden', 403);
        $b = body();
        $phone = normalizePHMobile(trim($b['phone'] ?? ''));
        if ($phone && !isValidPHMobile($phone)) json_err('Phone must be a valid Philippine number', 422);
        if ($phone && isPhoneTaken(db(), $phone, ['customers' => $targetId])) {
            json_err('Phone number already used by another account', 409);
        }
        db()->prepare('UPDATE customers SET fname=?,lname=?,phone=?,address=? WHERE id=?')
            ->execute([
                trim($b['fname']   ?? ''),
                trim($b['lname']   ?? ''),
                $phone,
                trim($b['address'] ?? ''),
                $targetId,
            ]);
        json_ok();

    case 'DELETE':
        require_auth(['admin']);
        if (!$id) json_err('id required');
        db()->prepare('UPDATE users SET status="inactive" WHERE id=?')->execute([$id]);
        json_ok();

    default:
        json_err('Method not allowed', 405);
}