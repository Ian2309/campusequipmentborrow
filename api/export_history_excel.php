<?php
// AdminPEMU: download an Excel-compatible CSV copy of equipment borrowing history.
require_once __DIR__ . '/../config/bootstrap.php';
require_admin();
post();

try {
    $rows = $pdo->query("
        SELECT r.receipt_no, s.student_id, s.first_name, s.last_name, s.course,
               r.course_code, e.name AS equipment_name, r.purpose,
               r.borrow_date, r.return_date, r.returned_at, r.status,
               r.waiver_signature, r.waiver_signed_at, r.admin_note, r.created_at
        FROM equipment_requests r
        JOIN students s  ON s.id = r.student_id
        JOIN equipment e ON e.id = r.equipment_id
        ORDER BY r.created_at DESC
    ")->fetchAll();

    $filename = 'rtu_borrowing_history_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Microsoft Excel detects UTF-8 encoding automatically
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Receipt No.',
        'Student Number',
        'Student Name',
        'Program',
        'Course Code',
        'Equipment',
        'Purpose',
        'Borrow Date',
        'Due Date',
        'Returned Date & Time',
        'Status',
        'Signed Waiver',
        'Waiver Signed At',
        'Admin Note',
        'Requested At',
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['receipt_no'] ?? '',
            $r['student_id'],
            trim($r['first_name'] . ' ' . $r['last_name']),
            $r['course'],
            $r['course_code'],
            $r['equipment_name'],
            $r['purpose'],
            $r['borrow_date'],
            $r['return_date'],
            !empty($r['returned_at']) ? date('Y-m-d h:i A', strtotime($r['returned_at'])) : '',
            $r['status'],
            $r['waiver_signature'],
            !empty($r['waiver_signed_at']) ? date('Y-m-d h:i A', strtotime($r['waiver_signed_at'])) : '',
            $r['admin_note'],
            !empty($r['created_at']) ? date('Y-m-d h:i A', strtotime($r['created_at'])) : '',
        ]);
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    error_log('export_history_excel.php: ' . $e->getMessage());
    fail('Could not export the borrowing history spreadsheet.', 500);
}