<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

use Firebase\JWT\JWT;

// Always set headers BEFORE any output
header('Content-Type: application/json; charset=UTF-8');

function respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/* INPUT */
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if ($username === '' || $password === '') {
    respond(400, ["message" => "Missing credentials"]);
}

/* USER */
$stmt = $pdo->prepare("SELECT users_id, username, password, role FROM tbl_users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    respond(401, ["message" => "Invalid username or password"]);
}

/* JWT */
$secretKey = getenv('JWT_SECRET') ?: 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';

$payload = [
    "iss" => "my-app",
    "iat" => time(),
    "exp" => time() + 3600,
    "sub" => $user['users_id'],
    "username" => $user['username'],
    "role" => $user['role']
];

$jwt = JWT::encode($payload, $secretKey, 'HS256');

respond(200, [
    "token" => $jwt,
    "user" => [
        "id" => $user['users_id'],
        "username" => $user['username'],
        "role" => $user['role']
    ]
]);
