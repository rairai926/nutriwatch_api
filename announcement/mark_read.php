<?php
ob_start(); session_start();
header("Content-Type: application/json; charset=utf-8");

$allowedOrigins = ["http://localhost:3000","http://127.0.0.1:3000","https://nutriwatch.com","http://192.168.1.36:3000"];
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin','user']);
$userId = (int)($authUser->sub ?? 0);

$body = json_decode(file_get_contents("php://input"), true);
$announcementId = (int)($body["announcement_id"] ?? 0);

if ($announcementId <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "announcement_id is required"]);
  exit;
}

// Insert if not exists (unique key prevents duplicates)
$stmt = $pdo->prepare("
  INSERT INTO tbl_announcement_reads (announcement_id, users_id, read_at)
  VALUES (?, ?, NOW())
  ON DUPLICATE KEY UPDATE read_at = read_at
");
$stmt->execute([$announcementId, $userId]);

echo json_encode(["ok" => true]);