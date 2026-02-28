<?php

session_start();
ob_start(); // optional safety

  header("Access-Control-Allow-Origin: *");
  header("Access-Control-Allow-Headers: Content-Type, Authorization");
  header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
  header("Content-Type: application/json");

  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
  }

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";

use Firebase\JWT\JWT;

// --------------------
// Settings
// --------------------
$FAIL_RESET_MINUTES = 10;   // ✅ Auto reset after 10 minutes
$FAIL_CAPTCHA_THRESHOLD = 2; // ✅ show captcha after 2 failed attempts

// --------------------
// Helpers
// --------------------
function json_input(): array {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function should_require_captcha_random(): bool {
  $p = getenv("CAPTCHA_PERCENT");
  $p = is_numeric($p) ? (int)$p : 0;
  if ($p < 0) $p = 0;
  if ($p > 100) $p = 100;
  return random_int(1, 100) <= $p;
}

function verify_turnstile(string $token): bool {
  $secret = getenv("TURNSTILE_SECRET_KEY");
  if (!$secret || $token === "") return false;

  $payload = http_build_query([
    "secret" => $secret,
    "response" => $token,
    "remoteip" => $_SERVER["REMOTE_ADDR"] ?? null,
  ]);

  $ch = curl_init("https://challenges.cloudflare.com/turnstile/v0/siteverify");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
    CURLOPT_TIMEOUT => 10,
  ]);

  $res = curl_exec($ch);
  curl_close($ch);

  if (!$res) return false;
  $json = json_decode($res, true);
  return !empty($json["success"]);
}

// --- Attempts logic (auto reset)
function get_fail_count(PDO $pdo, string $username, string $ip, int $resetMinutes): int {
  $stmt = $pdo->prepare("SELECT fail_count, last_failed_at FROM tbl_login_attempts WHERE username=? AND ip=? LIMIT 1");
  $stmt->execute([$username, $ip]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) return 0;

  $failCount = (int)$row["fail_count"];
  $lastFailedAt = strtotime($row["last_failed_at"] ?? "");

  // If last_failed_at invalid -> reset for safety
  if (!$lastFailedAt) {
    $del = $pdo->prepare("DELETE FROM tbl_login_attempts WHERE username=? AND ip=?");
    $del->execute([$username, $ip]);
    return 0;
  }

  $ageSeconds = time() - $lastFailedAt;
  $resetSeconds = $resetMinutes * 60;

  // ✅ auto reset if too old
  if ($ageSeconds > $resetSeconds) {
    $del = $pdo->prepare("DELETE FROM tbl_login_attempts WHERE username=? AND ip=?");
    $del->execute([$username, $ip]);
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
  $stmt = $pdo->prepare("DELETE FROM tbl_login_attempts WHERE username=? AND ip=?");
  $stmt->execute([$username, $ip]);
}

// --------------------
// INPUT
// --------------------
$data = json_input();
$username = trim($data["username"] ?? "");
$password = trim($data["password"] ?? "");
$captchaToken = trim($data["captchaToken"] ?? "");
$ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";

if ($username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["message" => "Missing credentials"]);
  exit;
}

// --------------------
// Decide if CAPTCHA is required
// Rule: CAPTCHA if fail_count >= 2 OR random%
// Keep decision in session for consistency
// --------------------
$failCount = get_fail_count($pdo, $username, $ip, $FAIL_RESET_MINUTES);
$requireByFails = ($failCount >= $FAIL_CAPTCHA_THRESHOLD);

if (!isset($_SESSION["captcha_decided"])) {
  $randomRequire = should_require_captcha_random();
  $_SESSION["captcha_required"] = ($requireByFails || $randomRequire);
  $_SESSION["captcha_decided"] = true;
  $_SESSION["captcha_decided_at"] = time();
} else {
  // if threshold reached later, enforce it
  if ($requireByFails) $_SESSION["captcha_required"] = true;
}

// Expire captcha decision after 5 min
if (isset($_SESSION["captcha_decided_at"]) && (time() - $_SESSION["captcha_decided_at"] > 300)) {
  unset($_SESSION["captcha_decided"], $_SESSION["captcha_required"], $_SESSION["captcha_decided_at"]);
  $randomRequire = should_require_captcha_random();
  $_SESSION["captcha_required"] = ($requireByFails || $randomRequire);
  $_SESSION["captcha_decided"] = true;
  $_SESSION["captcha_decided_at"] = time();
}

$captchaRequired = !empty($_SESSION["captcha_required"]);

// --------------------
// Enforce CAPTCHA if required
// --------------------
if ($captchaRequired) {
  if ($captchaToken === "") {
    http_response_code(403);
    echo json_encode([
      "message" => "CAPTCHA required. Please verify first.",
      "captcha_required" => true
    ]);
    exit;
  }

  if (!verify_turnstile($captchaToken)) {
    http_response_code(403);
    echo json_encode([
      "message" => "CAPTCHA verification failed. Please try again.",
      "captcha_required" => true
    ]);
    exit;
  }
}

// --------------------
// USER AUTH (your existing logic)
// --------------------
$stmt = $pdo->prepare("SELECT users_id, username, password, role FROM tbl_users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user["password"])) {
  // increment fail count
  inc_fail_count($pdo, $username, $ip);

  // re-check fail count (auto reset-aware)
  $newFailCount = get_fail_count($pdo, $username, $ip, $FAIL_RESET_MINUTES);
  $captchaNow = ($newFailCount >= $FAIL_CAPTCHA_THRESHOLD);

  http_response_code(401);
  echo json_encode([
    "message" => "Invalid username or password",
    "captcha_required" => $captchaNow ? true : false
  ]);
  exit;
}

// Success: reset fail counter
reset_fail_count($pdo, $username, $ip);

// --------------------
// JWT
// --------------------
$secretKey = getenv("JWT_SECRET") ?: "CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#";

$payload = [
  "iss" => "my-app",
  "iat" => time(),
  "exp" => time() + 3600,
  "sub" => $user["users_id"],
  "username" => $user["username"],
  "role" => $user["role"]
];

$jwt = JWT::encode($payload, $secretKey, "HS256");

// Clear captcha session state after successful login
unset($_SESSION["captcha_decided"], $_SESSION["captcha_required"], $_SESSION["captcha_decided_at"]);

echo json_encode([
  "token" => $jwt,
  "user" => [
    "id" => $user["users_id"],
    "username" => $user["username"],
    "role" => $user["role"]
  ]
]);