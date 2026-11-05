<?php
// Admin-only: dumps the whole database as an SQL file, keeps a copy in /backups, and sends it as a download.
require_once __DIR__ . '/../config/bootstrap.php';
require_admin();
post();

try {
    $sql = '-- CampusMate backup ' . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $sql .= "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";
        $rows = $pdo->query("SELECT * FROM `$t`");
        while ($row = $rows->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
            $sql .= "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

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
