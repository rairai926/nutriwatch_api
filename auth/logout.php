<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS
// --------------------
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "http://192.168.1.36:3000",
  "https://nutriwatch.com"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  echo json_encode(["message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function get_bearer_token(): string {
  $authHeader = '';

  if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
  }

  if ($authHeader === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
  }

  if ($authHeader === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  }

  if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    return trim($matches[1]);
  }

  return '';
}

function audit_log(PDO $pdo, ?int $userId, string $action, ?string $targetTable, ?string $targetId, ?string $description): void {
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
      $ip = trim(explode(',', $ip)[0]);
    }

    $stmt = $pdo->prepare("
      INSERT INTO tbl_audit_logs (user_id, action, target_table, target_id, description, ip_address)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
      $userId,
      $action,
      $targetTable,
      $targetId,
      $description,
      $ip !== '' ? $ip : null
    ]);
  } catch (Throwable $e) {
    error_log("Audit log failed: " . $e->getMessage());
  }
}

$token = get_bearer_token();

if ($token === '') {
  audit_log($pdo, null, 'LOGOUT_TOKEN_MISSING', 'jwt_blacklist', null, 'Logout attempted without bearer token');
  out(401, ["message" => "Token missing"]);
}

try {
  $secretKey = getenv("JWT_SECRET") ?: "CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#";
  $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));

  $userId = isset($decoded->sub) ? (int)$decoded->sub : null;
  $exp = isset($decoded->exp) ? (int)$decoded->exp : 0;

  if ($exp <= 0) {
    audit_log($pdo, $userId, 'LOGOUT_FAILED', 'jwt_blacklist', null, 'Decoded token missing exp');
    out(401, ["message" => "Invalid token"]);
  }

  // Prevent duplicate blacklist errors if token already logged out
  $check = $pdo->prepare("SELECT COUNT(*) FROM jwt_blacklist WHERE token = ?");
  $check->execute([$token]);
  $alreadyBlacklisted = (int)$check->fetchColumn() > 0;

  if (!$alreadyBlacklisted) {
    $stmt = $pdo->prepare("
      INSERT INTO jwt_blacklist (token, expires_at)
      VALUES (?, ?)
    ");
    $stmt->execute([$token, date('Y-m-d H:i:s', $exp)]);
  }

  audit_log(
    $pdo,
    $userId,
    'LOGOUT_SUCCESS',
    'jwt_blacklist',
    $userId !== null ? (string)$userId : null,
    $alreadyBlacklisted ? 'Logout requested; token already blacklisted' : 'Logged out successfully and token blacklisted'
  );

  echo json_encode(["message" => "Logged out successfully"]);
} catch (Throwable $e) {
  audit_log($pdo, null, 'LOGOUT_FAILED', 'jwt_blacklist', null, 'Invalid or undecodable token: ' . $e->getMessage());
  out(401, ["message" => "Invalid token"]);
}