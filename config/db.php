<?php



$host   = getenv('DB_HOST') ?? 'localhost';
$port   = getenv('DB_PORT') ?? '3306';
$dbname = getenv('DB_NAME') ?? '';
$user   = getenv('DB_USER') ?? '';
$pass   = getenv('DB_PASS') ?? '';

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
