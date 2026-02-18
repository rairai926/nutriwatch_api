<?php
require '../config/db.php';
require '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 🔐 Get token from Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["message" => "Token missing"]);
    exit;
}

$token = $matches[1];

try {
    // Decode token to get expiry
    $secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';
    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));


    $stmt = $pdo->prepare(
        "INSERT INTO jwt_blacklist (token, expires_at) VALUES (?, ?)"
    );
    $stmt->execute([$token, $decoded->exp]);

    echo json_encode(["message" => "Logged out successfully"]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid token"]);
}
