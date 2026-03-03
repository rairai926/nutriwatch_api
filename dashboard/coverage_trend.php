<?php
ob_start(); session_start();
header("Content-Type: application/json; charset=utf-8");

// CORS (same as above)
$allowedOrigins = ["http://localhost:3000","http://127.0.0.1:3000","https://nutriwatch.com","http://192.168.1.36:3000"];
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$user = authenticate(['admin', 'user']);
$role = $user->role ?? 'user';
$userId = (int)($user->sub ?? 0);

$barangayId = null;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);
  if ($barangayId <= 0) { http_response_code(403); echo json_encode(["message"=>"No barangay assigned"]); exit; }
}

$params = [];
$where = "";
if ($role !== 'admin') {
  $where = "WHERE ci.barangay_id = ?";
  $params[] = $barangayId;
}

// last 6 months: total children, measured children per month
$sql = "
  SELECT
    DATE_FORMAT(mo.month_start, '%Y-%m') AS ym,
    (
      SELECT COUNT(*) FROM tbl_child_info ci $where
    ) AS total_children,
    (
      SELECT COUNT(DISTINCT m.child_seq)
      FROM tbl_measurement m
      JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
      WHERE m.date_measured >= mo.month_start
        AND m.date_measured < DATE_ADD(mo.month_start, INTERVAL 1 MONTH)
        " . ($role !== 'admin' ? "AND ci.barangay_id = ?" : "") . "
    ) AS measured_children
  FROM (
    SELECT DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n MONTH) AS month_start
    FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) t
  ) mo
  ORDER BY mo.month_start ASC
";

$st = $pdo->prepare($sql);
$execParams = $params;
if ($role !== 'admin') {
  // measured subquery also needs barangay param again
  $execParams[] = $barangayId;
}
$st->execute($execParams);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$series = [];
foreach ($rows as $r) {
  $labels[] = $r["ym"];
  $total = (int)$r["total_children"];
  $meas = (int)$r["measured_children"];
  $pct = $total > 0 ? round(($meas / $total) * 100, 1) : 0.0;
  $series[] = $pct;
}

echo json_encode(["labels" => $labels, "series" => $series]);