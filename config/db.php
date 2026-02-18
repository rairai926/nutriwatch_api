<?php

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/.env');

$host   = $_ENV['DB_HOST'] ?? 'localhost';
$port   = $_ENV['DB_PORT'] ?? '3306';
$dbname = $_ENV['DB_NAME'] ?? '';
$user   = $_ENV['DB_USER'] ?? '';
$pass   = $_ENV['DB_PASS'] ?? '';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_SSL_CA       => __DIR__ . '/ca.pem'
];

try {

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}
