<?php
// Admin-only: exports all app data as a restorable .sql file, keeps a copy in /backups and sends it as a download.
// Restore: run database/schema.sql on an empty Supabase project, then run this file in the SQL Editor (or with psql).
// This is an application-level export. For full database backups also use Supabase's own backups / pg_dump.
require_once __DIR__ . '/../config/bootstrap.php';
require_admin();
post();

const BACKUP_TABLES = ['students', 'admins', 'equipment', 'equipment_requests', 'damage_reports', 'notifications', 'password_resets'];

function sql_value(PDO $pdo, $v): string {
    if ($v === null) return 'NULL';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (string)$v;
    return $pdo->quote((string)$v);
}

try {
    $all = implode(', ', BACKUP_TABLES);
    $sql = '-- CampusMate backup ' . date('c') . "\nBEGIN;\nTRUNCATE $all RESTART IDENTITY CASCADE;\n\n";
    foreach (BACKUP_TABLES as $t) {                       // fixed list, safe to interpolate
        $rows = $pdo->query("SELECT * FROM $t ORDER BY id");
        while ($row = $rows->fetch()) {
            $cols = implode(', ', array_keys($row));
            $vals = implode(', ', array_map(fn($v) => sql_value($pdo, $v), array_values($row)));
            $sql .= "INSERT INTO $t ($cols) VALUES ($vals);\n";
        }
        $sql .= "\n";
    }
    foreach (BACKUP_TABLES as $t) {
        $sql .= "SELECT setval(pg_get_serial_sequence('$t', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM $t;\n";
    }
    $sql .= "COMMIT;\n";

    $name = 'campusmate_' . date('Ymd_His') . '.sql';
    $dir  = __DIR__ . '/../backups';
    if (is_dir($dir) && is_writable($dir)) {
        file_put_contents("$dir/$name", $sql, LOCK_EX);
        $old = glob("$dir/campusmate_*.sql") ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 30)) as $f) @unlink($f); // keep the newest 30
    }

    header('Content-Type: application/sql; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$name\"");
    echo $sql;
    exit;
} catch (Throwable $e) {
    error_log($e->getMessage());
    fail('Backup failed. Check the server error log.', 500);
}
