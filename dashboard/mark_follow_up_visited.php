<?php
ob_start();
session_start();
header("Content-Type: application/json; charset=utf-8");

// CORS
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

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$user = authenticate(['admin', 'user']);
$userId = (int)($user->sub ?? 0);
$role = $user->role ?? 'user';

$input = json_decode(file_get_contents("php://input"), true);
$childSeq = (int)($input["child_seq"] ?? 0);
$note = trim((string)($input["note"] ?? ""));

if ($childSeq <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "child_seq is required"]);
  exit;
}

// If BNS/user, ensure child belongs to their barangay
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "No barangay assigned"]);
    exit;
  }

  $check = $pdo->prepare("
    SELECT COUNT(*)
    FROM tbl_child_info
    WHERE child_seq = ? AND barangay_id = ?
  ");
  $check->execute([$childSeq, $barangayId]);

  if (!(int)$check->fetchColumn()) {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Child is outside your barangay"]);
    exit;
  }
}

$st = $pdo->prepare("
  INSERT INTO tbl_follow_up_visits (child_seq, user_id, note)
  VALUES (?, ?, ?)
");
$ok = $st->execute([$childSeq, $userId, $note !== "" ? $note : null]);

echo json_encode([
  "ok" => (bool)$ok,
  "message" => $ok ? "Follow-up marked as visited." : "Failed to save follow-up visit."
]);