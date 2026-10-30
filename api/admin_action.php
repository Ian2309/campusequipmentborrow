<?php
// Admin: approve / reject a pending request, or mark an approved item as returned (optionally with a damage report).
require_once __DIR__ . '/../config/bootstrap.php';
$admin = require_admin();
$in = post();

$id     = (int)($in['request_id'] ?? 0);
$action = (string)($in['action'] ?? '');
$note   = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
if ($id <= 0 || !in_array($action, ['approve', 'reject', 'return'], true)) fail('Invalid request parameters.');

try {
    $pdo->beginTransaction();

    $s = $pdo->prepare('SELECT r.*, e.name AS item FROM equipment_requests r JOIN equipment e ON e.id = r.equipment_id WHERE r.id = ? FOR UPDATE');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) throw new UserError('Borrowing request not found.', 404);

    $sid = (int)$r['student_id'];
    $eid = (int)$r['equipment_id'];
    $item = $r['item'];
    $dates = date('M j', strtotime($r['borrow_date'])) . ' - ' . date('M j', strtotime($r['return_date']));
    $extra = $note !== '' ? " Note from PEMU: $note" : '';

    if ($action === 'approve' || $action === 'reject') {
        if ($r['status'] !== 'Pending Approval') throw new UserError('This request was already processed.', 409);
        $status = $action === 'approve' ? 'Approved' : 'Rejected';

        if ($action === 'approve') {
            $u = $pdo->prepare('UPDATE equipment SET available_units = available_units - 1 WHERE id = ? AND available_units > 0');
            $u->execute([$eid]);
            if ($u->rowCount() === 0) throw new UserError('Cannot approve: this item is out of stock.', 409);
        }
        $pdo->prepare('UPDATE equipment_requests SET status = ?, admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$status, $note, $admin['id'], $id]);

        notify('student', $sid, $action === 'approve' ? 'approved' : 'rejected',
            $action === 'approve'
                ? "Approved: you can borrow $item ($dates). Please claim it at the PEMU office.$extra"
                : "Request declined: $item ($dates).$extra", $id);
    } else {
        if ($r['status'] !== 'Approved') throw new UserError('Only approved requests can be marked as returned.', 409);

        $damaged = is_array($in['damage'] ?? null);
        if ($damaged) {
            $dm   = $in['damage'];
            $desc = mb_substr(trim((string)($dm['description'] ?? '')), 0, 500);
            if (mb_strlen($desc) < 5) throw new UserError('Please describe the damage.', 400);
            $sev  = in_array($dm['severity'] ?? '', ['Minor', 'Moderate', 'Severe'], true) ? $dm['severity'] : 'Minor';
            $cost = max(0, min(1000000, (float)($dm['cost'] ?? 0)));
            $unusable = !empty($dm['unusable']) ? 1 : 0;

            $pdo->prepare('INSERT INTO damage_reports (request_id, equipment_id, student_id, severity, description, estimated_cost, unusable, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $eid, $sid, $sev, $desc, $cost, $unusable, $admin['id']]);
        }

        // Stock: a good return (or repairable damage) goes back on the shelf; an unusable unit leaves the inventory.
        if ($damaged && $unusable) {
            $pdo->prepare('UPDATE equipment SET total_units = GREATEST(total_units - 1, 0) WHERE id = ?')->execute([$eid]);
        } else {
            $pdo->prepare('UPDATE equipment SET available_units = LEAST(available_units + 1, total_units) WHERE id = ?')->execute([$eid]);
        }

        $pdo->prepare('UPDATE equipment_requests SET status = ?, returned_at = NOW(), admin_note = COALESCE(NULLIF(?, \'\'), admin_note) WHERE id = ?')
            ->execute([$damaged ? 'Returned Damaged' : 'Returned', $note, $id]);

        notify('student', $sid, $damaged ? 'damage' : 'returned',
            $damaged
                ? "Return recorded with a damage report for $item ($sev). Please settle this with PEMU as agreed in your signed waiver.$extra"
                : "Thank you! Your return of $item was recorded in good condition.$extra", $id);
    }

    $pdo->commit();
    out(['success' => true]);
} catch (UserError $e) {
    rollback();
    fail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    rollback();
    error_log($e->getMessage());
    fail('Could not process the request. Please try again.', 500);
}
