<?php
// Command-line only. Usage (XAMPP):
//   C:\xampp\php\php.exe database\create_admin.php pemu "A-Strong-Passphrase" "PEMU Office"
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';

[, $user, $pass, $name] = $argv + [null, null, null, 'PEMU Administrator'];
if (!$user || !$pass || strlen($pass) < 10) exit("Usage: php create_admin.php <username> <password (10+ chars)> [\"Full Name\"]\n");

$pdo->prepare('INSERT INTO admins (username, password, full_name) VALUES (?, ?, ?)')
    ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), $name]);
echo "Admin '$user' created.\n";
