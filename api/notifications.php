<?php
// GET -> latest notifications + unread count for the logged-in user. POST {action:'read_all'} -> mark all read.
require_once __DIR__ . '/../config/bootstrap.php';
[$role, $user] = require_login();

if ($role === 'admin') { $where = "recipient_type = 'admin'"; $args = []; }
else { $where = "recipient_type = 'student' AND recipient_id = ?"; $args = [(int)$user['id']]; }

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = post();
        if (($in['action'] ?? '') !== 'read_all') fail('Invalid action.');
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE $where")->execute($args);
        out(['success' => true]);
    }
    $s = $pdo->prepare("SELECT id, kind, message, is_read, created_at FROM notifications WHERE $where ORDER BY id DESC LIMIT 50");
    $s->execute($args);
    $rows = $s->fetchAll();
    $u = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $where AND is_read = 0");
    $u->execute($args);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_read'] = (bool)$r['is_read']; }
    out(['success' => true, 'notifications' => $rows, 'unread' => (int)$u->fetchColumn()]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Notifications are unavailable right now.', 500);
}
