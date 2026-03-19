<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

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
require_once __DIR__ . '/../middleware/auth.php';

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $userId = (int)($authUser->sub ?? 0);

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $currentPassword = trim((string)($data['current_password'] ?? ''));
  $newPassword = trim((string)($data['new_password'] ?? ''));
  $confirmPassword = trim((string)($data['confirm_password'] ?? ''));

  if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    out(422, ["message" => "All password fields are required"]);
  }

  if (strlen($newPassword) < 8) {
    out(422, ["message" => "New password must be at least 8 characters"]);
  }

  if ($newPassword !== $confirmPassword) {
    out(422, ["message" => "New password and confirmation do not match"]);
  }

  $stmt = $pdo->prepare("
    SELECT users_id, password
    FROM tbl_users
    WHERE users_id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    out(404, ["message" => "User not found"]);
  }

  if (!password_verify($currentPassword, $user['password'])) {
    out(422, ["message" => "Current password is incorrect"]);
  }

  $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);

  $update = $pdo->prepare("
    UPDATE tbl_users
    SET
      password = ?,
      must_change_password = 0,
      password_changed_at = NOW(),
      status = 'active'
    WHERE users_id = ?
    LIMIT 1
  ");
  $update->execute([$newHash, $userId]);

  out(200, ["message" => "Password changed successfully"]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}