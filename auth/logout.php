<?php
// api/auth/logout.php
require_once __DIR__ . '/../../includes/helpers.php';
session_destroy();
json_ok(['redirect' => '/index.html']);