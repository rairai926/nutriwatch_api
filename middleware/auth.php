<?php
ob_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS (must NOT be * if you use withCredentials anywhere)
// --------------------
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://nutriwatch.com",
  "http://192.168.1.36:3000"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}


require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;


$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
  http_response_code(401);
  echo json_encode(["message" => "Unauthorized"]);
  exit;
}

$token = $matches[1];

// ✅ OPTIONAL: blacklist check (only if table exists)
$stmt = $pdo->prepare("SELECT id FROM jwt_blacklist WHERE token = ?");
$stmt->execute([$token]);
if ($stmt->fetch()) {
  http_response_code(401);
  echo json_encode(["message" => "Token revoked"]);
  exit;
}

// ✅ IMPORTANT: use SAME secret as login.php
$secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';

try {
  $authUser = JWT::decode($token, new Key($secretKey, 'HS256'));
} catch (Exception $e) {
  http_response_code(401);
  echo json_encode(["message" => "Invalid or expired token"]);
  exit;
}
