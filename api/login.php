<?php
require_once __DIR__ . '/../config/bootstrap.php';

$in       = post();
$role     = ($in['role'] ?? '') === 'admin' ? 'admin' : 'student';
$username = trim((string)($in['username'] ?? ''));
$password = (string)($in['password'] ?? '');

if ($username === '' && $password === '') out(['success' => false, 'message' => 'Please enter your login details.', 'field' => 'both'], 400);
if ($username === '') out(['success' => false, 'message' => ($role === 'admin' ? 'Please enter your admin username.' : 'Please enter your student number or RTU email.'), 'field' => 'username'], 400);
if ($password === '') out(['success' => false, 'message' => 'Please enter your password.', 'field' => 'password'], 400);
if (strlen($username) > 120 || strlen($password) > 200) out(['success' => false, 'message' => 'Invalid login details.', 'field' => 'both'], 401);

// Block any login input that looks like an email but isn't an @rtu.edu.ph domain
if ($role === 'student' && str_contains($username, '@') && !str_ends_with(strtolower($username), '@rtu.edu.ph')) {
    out(['success' => false, 'field' => 'username', 'message' => 'Only official @rtu.edu.ph emails or student numbers are permitted.'], 403);
}

$userKey = $role . ':' . strtolower($username);
$ipKey   = 'ip:' . client_ip();

function count_attempts(string $key): int {
    global $pdo;
    $c = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > NOW() - make_interval(mins => 15)');
    $c->execute([$key]);
    return (int)$c->fetchColumn();
}
function record_failure(string ...$keys): void {
    global $pdo;
    foreach ($keys as $k) $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$k]);
}

try {
    // Throttle brute force: 5 failures per account and 30 per device in 15 minutes.
    if (count_attempts($userKey) >= 5 || count_attempts($ipKey) >= 30) {
        fail('Too many failed attempts. Please try again in 15 minutes.', 429);
    }

    if ($role === 'admin') {
        $s = $pdo->prepare('SELECT id, password FROM admins WHERE LOWER(username) = LOWER(?)');
        $s->execute([$username]);
    } else {
        $s = $pdo->prepare('SELECT id, password, status FROM students WHERE LOWER(student_id) = LOWER(?) OR email = LOWER(?)');
        $s->execute([$username, $username]);
    }
    $u = $s->fetch();

    if (!$u) {
        record_failure($userKey, $ipKey);
        out(['success' => false, 'field' => 'username',
             'message' => $role === 'admin' ? 'Invalid admin username. Please try again!' : 'Invalid student number or email. Please try again!'], 401);
    }
    if (!password_verify($password, $u['password'])) {
        record_failure($userKey, $ipKey);
        out(['success' => false, 'field' => 'password', 'message' => 'Wrong password. Please try again!'], 401);
    }
    if ($role === 'student' && $u['status'] !== 'Active') fail('This account is suspended. Please contact PEMU.', 403);

    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? OR attempted_at < NOW() - make_interval(days => 1)')->execute([$userKey]);

    session_regenerate_id(true);
    $_SESSION['role'] = $role;
    $_SESSION['uid']  = (int)$u['id'];
    out(['success' => true, 'role' => $role, 'redirect' => $role === 'admin' ? 'admin/index.html' : 'student/index.html']);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Something went wrong. Please try again.', 500);
}