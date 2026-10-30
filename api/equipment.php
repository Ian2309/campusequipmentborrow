<?php
// Live equipment list for both student catalog and admin inventory.
require_once __DIR__ . '/../config/bootstrap.php';
require_login();

try {
    $rows = $pdo->query('SELECT id, name, category, total_units AS total, available_units AS available, description, image_url AS image FROM equipment ORDER BY name')->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['total'] = (int)$r['total']; $r['available'] = (int)$r['available']; }
    out(['success' => true, 'equipment' => $rows]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Failed to load equipment.', 500);
}
