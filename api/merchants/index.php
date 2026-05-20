<?php
// api/merchants/index.php

header('Content-Type: application/json'); // 🔴 IMPORTANT for fetch().json()

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id'] ?? param('id', 0));

try {

    switch ($method) {

        case 'GET':

            if ($id) {
                $s = db()->prepare('SELECT m.*, u.name AS created_by_name
                                    FROM merchants m
                                    LEFT JOIN users u ON u.id = m.created_by
                                    WHERE m.id = ?');
                $s->execute([$id]);
                $row = $s->fetch();

                if (!$row) {
                    json_err('Not found', 404);
                }

                json_ok($row);
            }

            $rows = db()->query('SELECT m.*, u.name AS created_by_name
                                 FROM merchants m
                                 LEFT JOIN users u ON u.id = m.created_by
                                 ORDER BY m.name')->fetchAll();

            json_ok($rows);
            break;

        case 'POST':

            $user = require_auth(['admin', 'employee']);

            $name      = trim($_POST['name'] ?? param('name', ''));
            $address   = trim($_POST['address'] ?? param('address', ''));
            $contact   = trim($_POST['contact'] ?? param('contact', ''));
            $latitude  = isset($_POST['latitude']) ? trim($_POST['latitude']) : (param('latitude', '') ?: null);
            $longitude = isset($_POST['longitude']) ? trim($_POST['longitude']) : (param('longitude', '') ?: null);

            if (!$name) {
                json_err('Merchant name is required', 400);
            }

            $imgPath = save_upload('image', 'merchants');
            $db = db();

            if (!$contact) {
                json_err('Contact number is required', 400);
            }
            if (!preg_match('/^[0-9]{10}$/', $contact)) {
                json_err('Contact must be exactly 10 digits with no letters or special characters', 400);
            }
            if (!isValidPHMobile($contact)) {
                json_err('Contact must be a valid Philippine mobile number starting with 9', 400);
            }

            $normalizedContact = normalizePHMobile($contact);
            if (isPhoneTaken($db, $normalizedContact, ['merchants' => $id])) {
                json_err('Contact number already used by another account', 409);
            }

            // UPDATE
            if ($id) {

                if ($imgPath) {
                    $db->prepare('UPDATE merchants 
                                  SET name=?, address=?, latitude=?, longitude=?, contact=?, image_path=?, updated_at=NOW()
                                  WHERE id=?')
                       ->execute([$name, $address, $latitude, $longitude, $contact, $imgPath, $id]);
                } else {
                    $db->prepare('UPDATE merchants 
                                  SET name=?, address=?, latitude=?, longitude=?, contact=?, updated_at=NOW()
                                  WHERE id=?')
                       ->execute([$name, $address, $latitude, $longitude, $contact, $id]);
                }

                json_ok(['message' => 'Updated successfully']);
            }

            // CREATE (admin only)
            require_auth(['admin']);

                $db->prepare('INSERT INTO merchants (name, address, latitude, longitude, contact, image_path, created_by)
                                  VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $address, $latitude, $longitude, $contact, $imgPath, $user['id']]);

            json_ok(['id' => (int) $db->lastInsertId()], 201);
            break;

        case 'DELETE':

            require_auth(['admin']);

            if (!$id) {
                json_err('id required', 400);
            }

            db()->prepare('DELETE FROM merchants WHERE id=?')->execute([$id]);

            json_ok(['message' => 'Deleted']);
            break;

        default:
            json_err('Method not allowed', 405);
    }

} catch (Throwable $e) {
    json_err('Server error: ' . $e->getMessage(), 500);
}