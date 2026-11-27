<?php
// ENDPOINT: User Logout (POST)
require_once __DIR__ . '/../config/bootstrap.php';
post();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
// ENDPOINT RESPONSE: JSON output confirming logout
out(['success' => true]);
