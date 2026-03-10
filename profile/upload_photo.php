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

  if (!isset($_FILES['photo'])) {
    out(422, ["message" => "No photo uploaded"]);
  }

  $file = $_FILES['photo'];

  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    out(400, ["message" => "Upload failed"]);
  }

  $maxSize = 2 * 1024 * 1024; // 2MB
  if (($file['size'] ?? 0) > $maxSize) {
    out(422, ["message" => "Photo must not exceed 2MB"]);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($mime, $allowedTypes, true)) {
    out(422, ["message" => "Only JPG, PNG, and WEBP are allowed"]);
  }

  $blob = file_get_contents($file['tmp_name']);
  if ($blob === false) {
    out(500, ["message" => "Failed to read uploaded file"]);
  }

  $sql = "
    UPDATE tbl_users
    SET
      profile_photo = :profile_photo,
      profile_photo_type = :profile_photo_type
    WHERE users_id = :users_id
  ";

  $st = $pdo->prepare($sql);
  $st->bindParam(':profile_photo', $blob, PDO::PARAM_LOB);
  $st->bindValue(':profile_photo_type', $mime);
  $st->bindValue(':users_id', $userId, PDO::PARAM_INT);
  $st->execute();

  out(200, ["message" => "Profile photo updated successfully"]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}