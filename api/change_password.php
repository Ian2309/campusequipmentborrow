<?php
// A logged-in student OR admin changes their own password.
// Makakapunta si student dito for temporary password from PEMU.
require_once __DIR__ . '/../config/bootstrap.php';

if (($_SESSION['role'] ?? '') === 'admin') { $user = require_admin(); $table = 'admins'; $min = 10; }
else { $user = require_student(true); $table = 'students'; $min = 8; }   
$in = post();

$cur = (string)($in['current'] ?? '');
$new = (string)($in['new'] ?? '');
if ($cur === '' || $new === '') fail('Please fill in all password fields.');
if ($err = password_error($new, $min)) fail($err);
if ($new === $cur) fail('Choose a new password that is different from the current one.');

try {
    $s = $pdo->prepare("SELECT password FROM $table WHERE id = ?");
    $s->execute([$user['id']]);
    if (!password_verify($cur, (string)$s->fetchColumn())) fail('Current password is incorrect.', 400);

    $set = $table === 'students' ? 'password = ?, must_change_password = false' : 'password = ?';
    $pdo->prepare("UPDATE $table SET $set WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    session_regenerate_id(true);
    out(['success' => true, 'message' => 'Password updated.']);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Could not update the password.', 500);
}