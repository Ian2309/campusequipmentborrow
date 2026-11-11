<?php
// Admin: add quantity to an item (restock) or delete an item.
// POST {action:'restock', id, qty}  -> raises total + available units and notifies every active student.
// POST {action:'delete', id}        -> removes an item that has never been borrowed (borrowed items keep their records).
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/storage.php';
require_admin();
$in = post();

$id     = (int)($in['id'] ?? 0);
$action = (string)($in['action'] ?? '');
if ($id <= 0 || !in_array($action, ['restock', 'delete'], true)) fail('Invalid request.');

try {
    $s = $pdo->prepare('SELECT id, name, total_units, available_units, image_url FROM equipment WHERE id = ?');
    $s->execute([$id]);
    $item = $s->fetch();
    if (!$item) fail('Equipment not found.', 404);

    if ($action === 'restock') {
        $qty = (int)($in['qty'] ?? 0);
        if ($qty < 1 || $qty > 1000) fail('Enter a quantity between 1 and 1000.');
        if ((int)$item['total_units'] + $qty > 100000) fail('That would make the total too large.');

        $pdo->prepare('UPDATE equipment SET total_units = total_units + ?, available_units = available_units + ? WHERE id = ?')
            ->execute([$qty, $qty, $id]);
        $avail = (int)$item['available_units'] + $qty;

        // One notification per active student. A failure here must not undo the restock.
        $notified = 0;
        try {
            $msg = "Restocked: {$item['name']} now has $avail available ($qty added). You can request it in the Equipment catalog.";
            $n = $pdo->prepare("INSERT INTO notifications (recipient_type, recipient_id, kind, message, is_read, created_at)
                                SELECT 'student', id, 'restocked', ?, false, NOW() FROM students WHERE status = 'Active'");
            $n->execute([$msg]);
            $notified = $n->rowCount();
        } catch (PDOException $e) {
            error_log('equipment_manage notify: ' . $e->getMessage());
        }
        out(['success' => true, 'notified' => $notified,
             'message' => "$qty unit(s) added to {$item['name']}." . ($notified ? " $notified student(s) notified." : ' Students could not be notified (see the server error log).')]);
    }

    // delete
    $c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM equipment_requests WHERE equipment_id = ?) + (SELECT COUNT(*) FROM damage_reports WHERE equipment_id = ?)');
    $c->execute([$id, $id]);
    if ((int)$c->fetchColumn() > 0) {
        fail("{$item['name']} has borrowing history, so it cannot be deleted without breaking the saved requests and signed waivers.", 409);
    }
    $pdo->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
    $img = (string)$item['image_url'];
    if (preg_match('#^uploads/equipment/[A-Za-z0-9_.-]+$#', $img)) @unlink(__DIR__ . '/../' . $img);
    elseif (storage_enabled()) storage_delete($img);
    out(['success' => true, 'message' => "{$item['name']} was deleted."]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail($e->getCode() === '23503' ? 'This item is still referenced by other records and cannot be deleted.' : 'Could not update the equipment.', 500);
}