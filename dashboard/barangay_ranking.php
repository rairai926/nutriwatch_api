<?php
ob_start(); session_start();
header("Content-Type: application/json; charset=utf-8");

// CORS
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

$user = authenticate(['admin']); // ✅ admin only

$quarterSql = "
  CASE
    WHEN QUARTER(CURDATE())=1 THEN MAKEDATE(YEAR(CURDATE()),1)
    WHEN QUARTER(CURDATE())=2 THEN STR_TO_DATE(CONCAT(YEAR(CURDATE()),'-04-01'),'%Y-%m-%d')
    WHEN QUARTER(CURDATE())=3 THEN STR_TO_DATE(CONCAT(YEAR(CURDATE()),'-07-01'),'%Y-%m-%d')
    ELSE STR_TO_DATE(CONCAT(YEAR(CURDATE()),'-10-01'),'%Y-%m-%d')
  END
";

$sql = "
  SELECT
    b.barangay_id,
    b.barangay_name,
    COUNT(DISTINCT ci.child_seq) AS child_count,
    COUNT(DISTINCT CASE WHEN m.date_measured >= ($quarterSql) THEN m.child_seq END) AS measured_this_quarter,
    MAX(m.date_measured) AS last_measurement_date
  FROM tbl_barangay b
  LEFT JOIN tbl_child_info ci ON ci.barangay_id = b.barangay_id
  LEFT JOIN tbl_measurement m ON m.child_seq = ci.child_seq
  GROUP BY b.barangay_id, b.barangay_name
  ORDER BY b.barangay_name ASC
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
  $child = (int)$r["child_count"];
  $meas  = (int)$r["measured_this_quarter"];
  $r["barangay_id"] = (int)$r["barangay_id"];
  $r["child_count"] = $child;
  $r["measured_this_quarter"] = $meas;
  $r["coverage_percent"] = $child > 0 ? round(($meas / $child) * 100, 1) : 0.0;
}

echo json_encode($rows);