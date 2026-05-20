<?php
// api/auth/me.php  — GET  returns current session user or 401
require_once __DIR__ . '/../../includes/helpers.php';
$user = current_user();
if (!$user) json_err('Not logged in', 401);
json_ok($user);