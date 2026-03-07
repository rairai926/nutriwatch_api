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
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $childSeq = (int)($_POST['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    out(422, ["message" => "Invalid child_seq"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);
    if ($userBarangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $checkSql = "SELECT child_seq FROM tbl_child_info WHERE child_seq = ?";
  $checkParams = [$childSeq];

  if ($role !== 'admin') {
    $checkSql .= " AND barangay_id = ?";
    $checkParams[] = $userBarangayId;
  }

  $checkSql .= " LIMIT 1";
  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);

  if (!$st->fetchColumn()) {
    out(404, ["message" => "Child not found"]);
  }

  if (empty($_FILES['photo']['name'])) {
    out(422, ["message" => "No photo uploaded"]);
  }

  $dir = __DIR__ . '/../uploads/children/';
  if (!is_dir($dir)) mkdir($dir, 0777, true);

  $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg', 'jpeg', 'png', 'webp'];
  if (!in_array($ext, $allowed, true)) {
    out(422, ["message" => "Invalid image type"]);
  }

  $filename = 'child_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $target = $dir . $filename;

  if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
    out(500, ["message" => "Failed to upload image"]);
  }

  $path = 'uploads/children/' . $filename;

  $st = $pdo->prepare("UPDATE tbl_child_info SET child_photo = ? WHERE child_seq = ?");
  $st->execute([$path, $childSeq]);

  out(200, [
    "message" => "Photo updated",
    "child_photo" => $path
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}