<?php
// Supabase (Postgres) connection.
// Credentials live in config/supabase.php (copy supabase.example.php). Environment variables of the same names also work.
if (is_file(__DIR__ . '/supabase.php')) require_once __DIR__ . '/supabase.php';

function sb_cfg(string $key, string $default = ''): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return $v !== false && $v !== '' ? $v : $default;
}

try {
    $host = sb_cfg('SB_DB_HOST');
    $port = sb_cfg('SB_DB_PORT', '5432');
    if ($host === '') throw new RuntimeException('config/supabase.php is missing or SB_DB_HOST is empty.');

    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=" . sb_cfg('SB_DB_NAME', 'postgres') . ';sslmode=require',
        sb_cfg('SB_DB_USER'),
        sb_cfg('SB_DB_PASS'),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Port 6543 is the transaction pooler, which cannot keep server-side prepared statements.
            PDO::ATTR_EMULATE_PREPARES   => $port === '6543',
        ]
    );
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable.']);
    exit(1);
}
