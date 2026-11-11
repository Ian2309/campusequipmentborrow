<?php
// Student (not logged in): asks PEMU for a temporary password.
// The reply is always the same, so this cannot be used to find out which student numbers or emails exist.
require_once __DIR__ . '/../config/bootstrap.php';

$in    = post();
$sid   = trim((string)($in['student_id'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));
if ($sid === '' || $email === '') fail('Please enter your student number and registered email.');
if (strlen($sid) > 20 || strlen($email) > 120) fail('Please check your details and try again.');

$reply = 'If those details match an account, PEMU has been notified. Please visit the PEMU office to receive your temporary password.';
$ipKey = 'reset-ip:' . client_ip();

try {
    $c = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > NOW() - make_interval(mins => 15)');
    $c->execute([$ipKey]);
    if ((int)$c->fetchColumn() >= 10) fail('Too many requests. Please try again in 15 minutes.', 429);
    $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$ipKey]);

    $s = $pdo->prepare("SELECT id, first_name, last_name, student_id FROM students WHERE LOWER(student_id) = LOWER(?) AND email = ? AND status = 'Active'");
    $s->execute([$sid, $email]);
    if ($u = $s->fetch()) {
        $p = $pdo->prepare("SELECT 1 FROM password_resets WHERE student_id = ? AND status = 'Pending'");
        $p->execute([$u['id']]);
        if (!$p->fetch()) {
            $pdo->prepare('INSERT INTO password_resets (student_id) VALUES (?)')->execute([$u['id']]);
            notify('admin', null, 'password_reset', "{$u['first_name']} {$u['last_name']} ({$u['student_id']}) asked for a password reset.");
        }
    }
    out(['success' => true, 'message' => $reply]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Could not send your request. Please try again.', 500);
}
