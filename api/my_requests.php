<?php
// A student's own borrowing history (replaces the old update_requests.php, which read a table that doesn't exist).
require_once __DIR__ . '/../config/bootstrap.php';
$student = require_student();

try {
    $s = $pdo->prepare('SELECT r.id, r.receipt_no, r.equipment_id, r.purpose, r.borrow_date, r.return_date, r.course_code, r.status, r.admin_note, r.created_at, e.name AS equipment_name FROM equipment_requests r JOIN equipment e ON e.id = r.equipment_id WHERE r.student_id = ? ORDER BY r.created_at DESC LIMIT 200');
    $s->execute([$student['id']]);
    out(['success' => true, 'requests' => $s->fetchAll()]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Failed to load your requests.', 500);
}