<?php
// Shared bootstrap for every API endpoint: headers, session, helpers and auth guards.
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

ini_set('session.use_strict_mode', '1');
session_name('campusmate');
session_set_cookie_params([
    'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

// Log out after 30 minutes of inactivity.
if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last'] = time();

require_once __DIR__ . '/database.php';

const WAIVER_VERSION = '2026-v1';
const WAIVER_TEXT = <<<'TXT'
EQUIPMENT BORROWING LIABILITY WAIVER AND AGREEMENT

1. I will use the borrowed equipment only for the academic purpose stated in my request.
2. I am responsible for the equipment from the moment I receive it until PEMU confirms its return.
3. I will return it on or before the return date, complete and in the same condition I received it.
4. If the equipment is damaged, lost, or returned incomplete because of my use or negligence, I agree to repair or replace it at my own cost, as assessed by PEMU. I understand that a damage record will be kept in my name.
5. I will not lend the equipment to anyone else.
6. Unsettled damage records or overdue items may lead to suspension of my borrowing privileges.
TXT;

class UserError extends Exception {}

function out(array $data, int $code = 200) { http_response_code($code); echo json_encode($data); exit; }
function fail(string $msg, int $code = 400) { out(['success' => false, 'message' => $msg], $code); }

function input(): array {
    $d = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($d) ? $d : [];
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

// Use at the top of every state-changing endpoint: enforces POST + CSRF token, returns JSON body.
function post(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed.', 405);
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        fail('Your session token is invalid. Please refresh the page.', 403);
    }
    return input();
}

// $allowPending: let a student with a temporary password through (only session + change-password use this).
function require_student(bool $allowPending = false): array {
    global $pdo;
    if (($_SESSION['role'] ?? '') !== 'student') fail('Please log in as a student.', 401);
    $s = $pdo->prepare('SELECT id, student_id, email, first_name, last_name, course, year_level, status, must_change_password FROM students WHERE id = ?');
    $s->execute([$_SESSION['uid']]);
    $u = $s->fetch();
    if (!$u || $u['status'] !== 'Active') { $_SESSION = []; fail('Account unavailable. Please log in again.', 401); }
    $u['must_change_password'] = (bool)$u['must_change_password'];
    if ($u['must_change_password'] && !$allowPending) fail('Please change your temporary password first.', 403);
    return $u;
}

function require_admin(): array {
    global $pdo;
    if (($_SESSION['role'] ?? '') !== 'admin') fail('Please log in as PEMU admin.', 401);
    $s = $pdo->prepare('SELECT id, username, full_name FROM admins WHERE id = ?');
    $s->execute([$_SESSION['uid']]);
    $u = $s->fetch();
    if (!$u) { $_SESSION = []; fail('Session expired. Please log in again.', 401); }
    return $u;
}

function require_login(): array {
    $role = $_SESSION['role'] ?? '';
    return [$role, $role === 'admin' ? require_admin() : require_student()];
}

function notify(string $to, ?int $recipientId, string $kind, string $message, ?int $requestId = null): void {
    global $pdo;
    $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, kind, message, request_id) VALUES (?, ?, ?, ?, ?)')
        ->execute([$to, $recipientId, $kind, $message, $requestId]);
}

// Password rule: minimum length plus at least one letter and one number.
function password_error(string $p, int $min = 8): ?string {
    if (strlen($p) < $min || strlen($p) > 200 || !preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
        return "Password must be at least $min characters and include both letters and numbers.";
    }
    return null;
}

function valid_date(string $d): ?DateTime {
    $x = DateTime::createFromFormat('!Y-m-d', $d);
    return ($x && $x->format('Y-m-d') === $d) ? $x : null;
}

function rollback(): void {
    global $pdo;
    if ($pdo->inTransaction()) $pdo->rollBack();
}
