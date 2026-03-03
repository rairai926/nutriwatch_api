<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS (shared)
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
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php"; // ✅ provides $pdo

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * authenticate(['admin','user'])
 * - Reads Authorization: Bearer <token>
 * - Verifies JWT using env JWT_SECRET
 * - Optional blacklist check if jwt_blacklist exists
 * - Enforces roles if provided
 * - Returns decoded token payload object
 */
function authenticate(array $allowedRoles = []) {
  global $pdo;

  // Get Authorization header safely
  $authHeader = $_SERVER["HTTP_AUTHORIZATION"]
    ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"]
    ?? (getallheaders()["Authorization"] ?? "");

  if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["message" => "Unauthorized"]);
    exit;
  }

  $token = $matches[1];

  // ✅ Optional blacklist check (only if table exists)
  try {
    $check = $pdo->query("SHOW TABLES LIKE 'jwt_blacklist'");
    $exists = $check && $check->fetch();

    if ($exists) {
      $stmt = $pdo->prepare("SELECT id FROM jwt_blacklist WHERE token = ? LIMIT 1");
      $stmt->execute([$token]);
      if ($stmt->fetch()) {
        http_response_code(401);
        echo json_encode(["message" => "Token revoked"]);
        exit;
      }
    }
  } catch (Exception $e) {
    // if DB/table issues, do NOT crash auth; continue JWT verification
  }

  // ✅ IMPORTANT: use SAME secret as login.php
  $secretKey = getenv("JWT_SECRET") ?: "CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#";

  try {
    $decoded = JWT::decode($token, new Key($secretKey, "HS256"));
  } catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid or expired token"]);
    exit;
  }

  // Role restriction (if provided)
  $role = $decoded->role ?? null;
  if (!empty($allowedRoles) && (!is_string($role) || !in_array($role, $allowedRoles, true))) {
    http_response_code(403);
    echo json_encode(["message" => "Forbidden"]);
    exit;
  }

  return $decoded;
}