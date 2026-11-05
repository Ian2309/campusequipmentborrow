<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_admin();

try {
    $rows = $pdo->query("
        SELECT r.id, r.receipt_no, r.equipment_id, r.purpose, r.borrow_date, r.return_date, r.course_code, r.status, r.admin_note,
               r.waiver_signature, r.waiver_signed_at, r.created_at, r.returned_at,
               s.first_name, s.last_name, s.student_id, s.course, e.name AS equipment_name
        FROM equipment_requests r
        JOIN students s  ON s.id = r.student_id
        JOIN equipment e ON e.id = r.equipment_id
        ORDER BY r.created_at DESC LIMIT 500
    ")->fetchAll();
    out(['success' => true, 'requests' => $rows]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Failed to load requests.', 500);
}
