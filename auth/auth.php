<?php

ob_start();

$allowedOrigins = [
    "http://localhost:3000",
    "http://127.0.0.1:3000",
    "http://192.168.1.36:3000",
    "https://nutriwatch.com",
    "https://nutriwatch-cyan.vercel.app"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/* ================== TOKEN ================== */
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($authHeader)) {
    http_response_code(401);
    echo json_encode(["message" => "Missing Authorization header"]);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid Authorization format"]);
    exit;
}

$token = $matches[1];

try {
    $secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';
    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
    $authUser = $decoded;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid or expired token"]);
    exit;
}