<?php
// Admin: damage records with the waiver the student signed. POST {id} marks a record as settled.
require_once __DIR__ . '/../config/bootstrap.php';
require_admin();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = post();
        $id = (int)($in['id'] ?? 0);
        $s = $pdo->prepare('SELECT d.id, d.student_id, d.request_id, e.name FROM damage_reports d JOIN equipment e ON e.id = d.equipment_id WHERE d.id = ? AND d.resolved = false');
        $s->execute([$id]);
        $d = $s->fetch();
        if (!$d) fail('Damage record not found or already settled.', 404);
        $pdo->prepare('UPDATE damage_reports SET resolved = true, resolved_at = NOW() WHERE id = ?')->execute([$id]);
        notify('student', (int)$d['student_id'], 'damage_settled', "Your damage record for {$d['name']} has been marked as settled by PEMU.", (int)$d['request_id']);
        out(['success' => true]);
    }

    $rows = $pdo->query('
        SELECT d.id, d.severity, d.description, d.estimated_cost, d.unusable, d.resolved, d.created_at,
               e.name AS equipment_name, s.first_name, s.last_name, s.student_id,
               r.borrow_date, r.return_date, r.waiver_signature, r.waiver_signed_at, r.waiver_version
        FROM damage_reports d
        JOIN equipment e ON e.id = d.equipment_id
        JOIN students s  ON s.id = d.student_id
        JOIN equipment_requests r ON r.id = d.request_id
        ORDER BY d.resolved ASC, d.created_at DESC
    ')->fetchAll();
    foreach ($rows as &$r) { $r['unusable'] = (bool)$r['unusable']; $r['resolved'] = (bool)$r['resolved']; $r['estimated_cost'] = (float)$r['estimated_cost']; }
    out(['success' => true, 'damages' => $rows]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Damage records are unavailable right now.', 500);
}
