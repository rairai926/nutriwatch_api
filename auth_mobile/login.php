<?php
ob_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS
// --------------------
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "http://192.168.1.36:3000"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";

use Firebase\JWT\JWT;

// --------------------
// SETTINGS
// --------------------
$FAIL_RESET_MINUTES = getenv("FAIL_RESET_MINUTES");
$FAIL_RESET_MINUTES = is_numeric($FAIL_RESET_MINUTES) ? (int)$FAIL_RESET_MINUTES : 10;
if ($FAIL_RESET_MINUTES < 1) $FAIL_RESET_MINUTES = 1;

// --------------------
// HELPERS
// --------------------
function json_input(): array {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function get_fail_count(PDO $pdo, string $username, string $ip, int $resetMinutes): int {
  $stmt = $pdo->prepare("SELECT fail_count, last_failed_at FROM tbl_login_attempts WHERE username=? AND ip=? LIMIT 1");
  $stmt->execute([$username, $ip]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) return 0;

  $failCount = (int)$row["fail_count"];
  $lastFailedAt = strtotime($row["last_failed_at"] ?? "");

  if (!$lastFailedAt) return 0;

  $ageSeconds = time() - $lastFailedAt;
  if ($ageSeconds > ($resetMinutes * 60)) {
    $pdo->prepare("DELETE FROM tbl_login_attempts WHERE username=? AND ip=?")
        ->execute([$username, $ip]);
    return 0;
  }

  return $failCount;
}

function inc_fail_count(PDO $pdo, string $username, string $ip): void {
  $stmt = $pdo->prepare("
    INSERT INTO tbl_login_attempts (username, ip, fail_count, last_failed_at)
    VALUES (?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE
      fail_count = fail_count + 1,
      last_failed_at = NOW()
  ");
  $stmt->execute([$username, $ip]);
}

function reset_fail_count(PDO $pdo, string $username, string $ip): void {
  $pdo->prepare("DELETE FROM tbl_login_attempts WHERE username=? AND ip=?")
      ->execute([$username, $ip]);
}

// --------------------
// INPUT
// --------------------
$data = json_input();

$username = trim($data["username"] ?? "");
$password = trim($data["password"] ?? "");
$ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";

if ($username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["message" => "Missing credentials"]);
  exit;
}

// --------------------
// USER AUTH
// --------------------
$stmt = $pdo->prepare("
  SELECT users_id, username, password, role
  FROM tbl_users
  WHERE username = ?
  LIMIT 1
");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user["password"])) {
  inc_fail_count($pdo, $username, $ip);

  http_response_code(401);
  echo json_encode([
    "message" => "Invalid username or password"
  ]);
  exit;
}

// SUCCESS
reset_fail_count($pdo, $username, $ip);

// --------------------
// JWT
// --------------------
$secretKey = getenv("JWT_SECRET") ?: "CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#";

$payload = [
  "iss" => "nutriwatch-mobile",
  "iat" => time(),
  "exp" => time() + 86400, // 🔥 24 hours for mobile
  "sub" => $user["users_id"],
  "username" => $user["username"],
  "role" => $user["role"]
];

$jwt = JWT::encode($payload, $secretKey, "HS256");

// --------------------
// RESPONSE
// --------------------
echo json_encode([
  "ok" => true,
  "token" => $jwt,
  "user" => [
    "id" => $user["users_id"],
    "username" => $user["username"],
    "role" => $user["role"]
  ]
]);