<?php
require_once __DIR__ . '/../config/bootstrap.php';

$in    = post();
$first = trim((string)($in['firstname'] ?? ''));
$last  = trim((string)($in['lastname'] ?? ''));
$sid   = trim((string)($in['student_id'] ?? ''));
$email = strtolower(trim((string)($in['email'] ?? '')));
$course = trim((string)($in['course'] ?? ''));
$year  = trim((string)($in['year_level'] ?? ''));
$pass  = (string)($in['password'] ?? '');

if ($first === '' || $last === '' || $sid === '' || $email === '' || $course === '' || $year === '' || $pass === '') {
    fail('Please complete all registration fields.');
}
if (mb_strlen($first) > 50 || mb_strlen($last) > 50 || mb_strlen($course) > 80) fail('One of the fields is too long.');
if (!preg_match('/^[A-Za-z0-9-]{4,20}$/', $sid)) fail('Student number may only contain letters, numbers and dashes (4-20 characters).');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) fail('Please enter a valid email address.');

// Strictly enforce @rtu.edu.ph email domain
if (!str_ends_with($email, '@rtu.edu.ph')) {
    fail('Registration restricted. Only official @rtu.edu.ph institutional email addresses are allowed.');
}

if (!in_array($year, ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'], true)) fail('Please choose a valid year level.');
if ($err = password_error($pass)) fail($err);

try {
    $check = $pdo->prepare('SELECT id FROM students WHERE student_id = ? OR email = ?');
    $check->execute([$sid, $email]);
    if ($check->fetch()) fail('That student number or email is already registered.', 409);

    $pdo->prepare("INSERT INTO students (student_id, email, password, first_name, last_name, course, year_level, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')")
        ->execute([$sid, $email, password_hash($pass, PASSWORD_DEFAULT), $first, $last, $course, $year]);

    out(['success' => true, 'message' => 'Account created! You can now log in.']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail('That student number or email is already registered.', 409);
    error_log($e->getMessage());
    fail('Could not create the account. Please try again.', 500);
}