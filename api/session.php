   <?php
   // Who is logged in? Also hands the browser its CSRF token.
   require_once __DIR__ . '/../config/bootstrap.php';

   $role = $_SESSION['role'] ?? null;
   $user = null;
   try {
       if ($role === 'student') { $user = require_student(true); unset($user['status']); }
       elseif ($role === 'admin') { $user = require_admin(); }
   } catch (PDOException $e) {
       error_log($e->getMessage());
       fail('Service temporarily unavailable.', 500);
   }
   out(['success' => true, 'authenticated' => (bool)$user, 'role' => $user ? $role : null, 'user' => $user, 'csrf' => csrf_token()]);