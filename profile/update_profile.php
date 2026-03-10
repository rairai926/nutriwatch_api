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

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

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

  $firstname = trim((string)($data['firstname'] ?? ''));
  $middlename = trim((string)($data['middlename'] ?? ''));
  $lastname = trim((string)($data['lastname'] ?? ''));
  $newPassword = trim((string)($data['password'] ?? ''));

  if ($firstname === '' || $lastname === '') {
    out(422, ["message" => "First name and last name are required"]);
  }

  if ($newPassword !== '' && strlen($newPassword) < 8) {
    out(422, ["message" => "Password must be at least 8 characters"]);
  }

  if ($newPassword !== '') {
    $passwordHash = password_hash($newPassword, PASSWORD_ARGON2ID);

    $sql = "
      UPDATE tbl_users
      SET
        firstname = :firstname,
        middlename = :middlename,
        lastname = :lastname,
        password = :password
      WHERE users_id = :users_id
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':firstname' => $firstname,
      ':middlename' => $middlename,
      ':lastname' => $lastname,
      ':password' => $passwordHash,
      ':users_id' => $userId
    ]);
  } else {
    $sql = "
      UPDATE tbl_users
      SET
        firstname = :firstname,
        middlename = :middlename,
        lastname = :lastname
      WHERE users_id = :users_id
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':firstname' => $firstname,
      ':middlename' => $middlename,
      ':lastname' => $lastname,
      ':users_id' => $userId
    ]);
  }

  out(200, ["message" => "Profile updated successfully"]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}