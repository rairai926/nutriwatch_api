<?php
header("Content-Type: application/json; charset=utf-8");

$host   = getenv('DB_HOST') ?: 'localhost';
$port   = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: '';
$user   = getenv('DB_USER') ?: '';
$pass   = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$useSSL = getenv('DB_SSL') === 'true';

if ($useSSL) {
    $ca = __DIR__ . '/ca.pem';

    if (file_exists($ca) && filesize($ca) > 0) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    }
}

try {
    if (!$host || !$dbname || !$user) {
        throw new Exception("Missing DB environment variables.");
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => "Database connection failed",
        "error" => $e->getMessage()
    ]);
    exit;
}