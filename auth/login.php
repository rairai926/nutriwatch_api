<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS (IMPORTANT for session cookies)
// --------------------
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  // add your deployed frontend domain(s)
  "https://nutriwatch.com",
  "https://www.nutriwatch.com"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
} else {
  // If you want to block unknown origins:
  // http_response_code(403);
  // echo json_encode(["message" => "Origin not allowed"]);
  // exit;
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
// Helpers
// --------------------
function json_input(): array {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function should_require_captcha(): bool {
  // set in Render env: CAPTCHA_PERCENT=10 / 20 / 30
  $p = getenv("CAPTCHA_PERCENT");
  $p = is_numeric($p) ? (int)$p : 0;
  if ($p < 0) $p = 0;
  if ($p > 100) $p = 100;

  $r = random_int(1, 100);
  return $r <= $p;
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

// --------------------
// INPUT
// --------------------
$data = json_input();

$username = trim($data["username"] ?? "");
$password = trim($data["password"] ?? "");
$captchaToken = trim($data["captchaToken"] ?? "");

if ($username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["message" => "Missing credentials"]);
  exit;
}

// --------------------
// Decide CAPTCHA randomly ONCE per session attempt
// --------------------
if (!isset($_SESSION["captcha_decided"])) {
  $_SESSION["captcha_required"] = should_require_captcha();
  $_SESSION["captcha_decided"] = true;
  $_SESSION["captcha_decided_at"] = time();
}

// optional: expire decision after 5 minutes
if (isset($_SESSION["captcha_decided_at"]) && (time() - $_SESSION["captcha_decided_at"] > 300)) {
  unset($_SESSION["captcha_decided"], $_SESSION["captcha_required"], $_SESSION["captcha_decided_at"]);
  $_SESSION["captcha_required"] = should_require_captcha();
  $_SESSION["captcha_decided"] = true;
  $_SESSION["captcha_decided_at"] = time();
}

$captchaRequired = !empty($_SESSION["captcha_required"]);

// --------------------
// If CAPTCHA is required, enforce it
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
  http_response_code(401);
  echo json_encode(["message" => "Invalid username or password"]);
  exit;
}

// --------------------
// JWT
// --------------------
// ✅ Better: put this in Render env as JWT_SECRET instead of hardcoding
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

// ✅ clear captcha state after successful login
unset($_SESSION["captcha_decided"], $_SESSION["captcha_required"], $_SESSION["captcha_decided_at"]);

echo json_encode([
  "token" => $jwt,
  "user" => [
    "id" => $user["users_id"],
    "username" => $user["username"],
    "role" => $user["role"]
  ]
]);