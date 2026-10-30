<?php
// Admin: list password-reset requests (GET) and issue a one-time temporary password (POST {id}).
require_once __DIR__ . '/../config/bootstrap.php';
$admin = require_admin();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = post();
        $id = (int)($in['id'] ?? 0);
        $pdo->beginTransaction();
        $s = $pdo->prepare("SELECT pr.id, pr.student_id, s.student_id AS sid, s.email FROM password_resets pr JOIN students s ON s.id = pr.student_id WHERE pr.id = ? AND pr.status = 'Pending' FOR UPDATE");
        $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) throw new UserError('Reset request not found or already handled.', 404);

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'; // no look-alike characters
        $temp = '';
        for ($i = 0; $i < 10; $i++) $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];

        $pdo->prepare('UPDATE students SET password = ?, must_change_password = 1 WHERE id = ?')->execute([password_hash($temp, PASSWORD_DEFAULT), $r['student_id']]);
        $pdo->prepare("UPDATE password_resets SET status = 'Done', handled_by = ?, handled_at = NOW() WHERE id = ?")->execute([$admin['id'], $id]);
        $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? OR identifier = ?')->execute(['student:' . strtolower($r['sid']), 'student:' . strtolower($r['email'])]);
        $pdo->commit();
        out(['success' => true, 'temp_password' => $temp]); // shown once; only the hash is stored
    }

    $rows = $pdo->query("
        SELECT pr.id, pr.status, pr.created_at, pr.handled_at, s.first_name, s.last_name, s.student_id, s.email, s.course
        FROM password_resets pr JOIN students s ON s.id = pr.student_id
        ORDER BY pr.status = 'Pending' DESC, pr.created_at DESC LIMIT 100
    ")->fetchAll();
    out(['success' => true, 'resets' => $rows]);
} catch (UserError $e) {
    rollback();
    fail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    rollback();
    error_log($e->getMessage());
    fail('Password resets are unavailable right now.', 500);
}