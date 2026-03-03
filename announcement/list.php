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
header("Access-Control-Allow-Methods: GET, OPTIONS");
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin','user']);
$role = $authUser->role ?? 'user';
$userId = (int)($authUser->sub ?? 0);

$today = date('Y-m-d');

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
    SELECT a.announcement_id, a.title, a.message, a.date_start, a.date_end, a.date_posted, a.venue,
           a.is_global, a.barangay_id,
           (SELECT 1 FROM tbl_announcement_reads r WHERE r.announcement_id=a.announcement_id AND r.users_id=? LIMIT 1) AS is_read
    FROM tbl_announcement a
    WHERE a.date_start <= ? AND a.date_end >= ?
    ORDER BY a.date_posted DESC
    LIMIT 20
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId, $today, $today]);
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
  exit;
}

// BNS: global OR targeted barangay
$sql = "
  SELECT a.announcement_id, a.title, a.message, a.date_start, a.date_end, a.date_posted, a.venue,
         a.is_global, a.barangay_id,
         (SELECT 1 FROM tbl_announcement_reads r WHERE r.announcement_id=a.announcement_id AND r.users_id=? LIMIT 1) AS is_read
  FROM tbl_announcement a
  WHERE a.date_start <= ? AND a.date_end >= ?
    AND (
      a.is_global = 1
      OR (a.is_global = 0 AND a.barangay_id = ?)
    )
  ORDER BY a.date_posted DESC
  LIMIT 20
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId, $today, $today, $barangayId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));