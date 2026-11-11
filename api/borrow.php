<?php
// GET  -> returns the waiver text students must accept.
// POST -> student submits a borrow request with a signed online waiver.
require_once __DIR__ . '/../config/bootstrap.php';
$student = require_student();

if ($_SERVER['REQUEST_METHOD'] === 'GET') out(['success' => true, 'waiver' => WAIVER_TEXT, 'version' => WAIVER_VERSION]);

$in        = post();
$equipId   = (int)($in['equipment_id'] ?? 0);
$purpose   = trim((string)($in['purpose'] ?? ''));
$course    = trim((string)($in['course_code'] ?? ''));
$agreed    = ($in['waiver_agreed'] ?? false) === true;
$signature = preg_replace('/\s+/', ' ', trim((string)($in['signature'] ?? '')));
$borrow    = valid_date((string)($in['borrow_date'] ?? ''));
$return    = valid_date((string)($in['return_date'] ?? ''));
$fullName  = $student['first_name'] . ' ' . $student['last_name'];

if ($equipId <= 0 || $purpose === '' || $course === '') fail('Please complete all fields.');
if (mb_strlen($purpose) < 5 || mb_strlen($purpose) > 300) fail('Purpose must be 5-300 characters.');
if (!preg_match('/^[A-Za-z0-9 -]{2,20}$/', $course)) fail('Enter a valid course code (e.g. ITEC 101).');
if (!$borrow || !$return) fail('Please choose valid borrow and return dates.');
if ($borrow < new DateTime('today')) fail('Borrow date cannot be in the past.');
if ($return < $borrow) fail('Return date cannot be before the borrow date.');
if ($borrow->diff($return)->days > 14) fail('Equipment can be borrowed for 14 days at most.');
if (!$agreed) fail('You must agree to the liability waiver.');
if (mb_strtolower($signature) !== mb_strtolower(preg_replace('/\s+/', ' ', $fullName))) {
    fail("Type your full name exactly as registered ($fullName) to sign the waiver.");
}

try {
    $pdo->beginTransaction();

    $e = $pdo->prepare('SELECT name, available_units FROM equipment WHERE id = ? FOR UPDATE');
    $e->execute([$equipId]);
    $item = $e->fetch();
    if (!$item) throw new UserError('Equipment not found.', 404);
    if ($item['available_units'] < 1) throw new UserError('Sorry, this item is out of stock right now.', 409);

    $d = $pdo->prepare("SELECT 1 FROM equipment_requests WHERE student_id = ? AND equipment_id = ? AND status IN ('Pending Approval','Approved') LIMIT 1");
    $d->execute([$student['id'], $equipId]);
    if ($d->fetch()) throw new UserError('You already have an active request for this item.', 409);

    $receipt = new_receipt_no();
    $ins = $pdo->prepare('INSERT INTO equipment_requests (receipt_no, student_id, equipment_id, purpose, borrow_date, return_date, course_code, waiver_signed, waiver_signature, waiver_version, waiver_signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, true, ?, ?, NOW()) RETURNING id');
    $ins->execute([$receipt, $student['id'], $equipId, $purpose, $borrow->format('Y-m-d'), $return->format('Y-m-d'), $course, $fullName, WAIVER_VERSION]);
    $reqId = (int)$ins->fetchColumn();

    $range = $borrow->format('M j') . ' - ' . $return->format('M j');
    notify('admin', null, 'new_request', "Receipt $receipt: $fullName ({$student['student_id']}) wants to borrow {$item['name']} ($range).", $reqId);
    notify('student', (int)$student['id'], 'submitted', "Request received: {$item['name']} ($range). Receipt no. $receipt. Your signed waiver is on file. PEMU will review it shortly.", $reqId);

    $pdo->commit();
    out(['success' => true, 'receipt' => [
        'receipt_no' => $receipt, 'equipment' => $item['name'],
        'borrow_date' => $borrow->format('Y-m-d'), 'return_date' => $return->format('Y-m-d'),
        'purpose' => $purpose, 'course_code' => $course, 'created_at' => date('Y-m-d H:i:s'),
    ]]);
} catch (UserError $e) {
    rollback();
    fail($e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    rollback();
    error_log($e->getMessage());
    fail('Could not submit your request. Please try again.', 500);
}
