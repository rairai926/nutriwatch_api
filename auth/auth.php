<?php
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/* ================== HEADERS ================== */
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "http://192.168.1.36:3000",
  "http://172.77.4.94:3000"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}


/* ================== TOKEN ================== */
$headers = getallheaders();

if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(["message" => "Missing Authorization header"]);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);

try {
    $secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';

    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));

    // ✅ token valid — user info available
    $authUser = $decoded;

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid or expired token"]);
    exit;
}
