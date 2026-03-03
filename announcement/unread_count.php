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
header("Access-Control-Allow-Methods: GET, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user']);
$role = $authUser->role ?? 'user';
$userId = (int)($authUser->sub ?? 0);

// BNS scope
$barangayId = 0;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["message" => "No barangay assigned"]);
    exit;
  }
}

if ($role === 'admin') {
  $sql = "
    SELECT COUNT(*)
    FROM tbl_announcement a
    WHERE a.active = 1
      AND a.date_start <= CURDATE()
      AND a.date_end >= CURDATE()
      AND NOT EXISTS (
        SELECT 1 FROM tbl_announcement_reads r
        WHERE r.announcement_id=a.announcement_id AND r.users_id=?
      )
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId]);
  echo json_encode(["unread" => (int)$stmt->fetchColumn()]);
  exit;
}

$sql = "
  SELECT COUNT(*)
  FROM tbl_announcement a
  WHERE a.active = 1
    AND a.date_start <= CURDATE()
    AND a.date_end >= CURDATE()
    AND (
      a.is_global = 1
      OR (a.is_global = 0 AND a.barangay_id = ?)
    )
    AND NOT EXISTS (
      SELECT 1 FROM tbl_announcement_reads r
      WHERE r.announcement_id=a.announcement_id AND r.users_id=?
    )
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$barangayId, $userId]);
echo json_encode(["unread" => (int)$stmt->fetchColumn()]);