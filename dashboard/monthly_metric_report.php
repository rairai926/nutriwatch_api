<?php

ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// CORS
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://nutriwatch.com",
  "http://192.168.1.36:3000",
  "https://nutriwatch-cyan.vercel.app"
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

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

function monthLabels() {
  return ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
}

$metric = trim($_GET["metric"] ?? "lt_status");
$allowed = ["lt_status", "weight_status", "height_status", "muac_status"];

if (!in_array($metric, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid metric"]);
  exit;
}

$year = (int)($_GET["year"] ?? date("Y"));
if ($year < 2000 || $year > 2100) {
  $year = (int)date("Y");
}

$filterBarangayId = (int)($_GET["barangay_id"] ?? 0);

// --------------------
// Barangay scope
// Admin: all or selected barangay
// User/BNS: assigned barangay only
// --------------------
$scopeWhere = "";
$scopeParams = [];

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $assignedBarangayId = (int)($st->fetchColumn() ?: 0);

  if ($assignedBarangayId <= 0) {
    echo json_encode([
      "ok" => true,
      "year" => $year,
      "barangay_id" => 0,
      "labels" => monthLabels(),
      "series" => [
        ["name" => "Normal", "data" => array_fill(0, 12, 0)],
        ["name" => "Malnourished", "data" => array_fill(0, 12, 0)]
      ]
    ]);
    exit;
  }

  $scopeWhere = " AND ci.barangay_id = ? ";
  $scopeParams[] = $assignedBarangayId;
  $filterBarangayId = $assignedBarangayId;
} else {
  if ($filterBarangayId > 0) {
    $scopeWhere = " AND ci.barangay_id = ? ";
    $scopeParams[] = $filterBarangayId;
  }
}

// --------------------
// Normal expression
// --------------------
$normalExpr = "LOWER(COALESCE(m.$metric, '')) = 'normal'";

if ($metric === "muac_status") {
  $normalExpr = "
    (
      LOWER(COALESCE(m.muac_status, '')) LIKE '%green%'
      OR LOWER(COALESCE(m.muac_status, '')) LIKE '%normal%'
    )
  ";
}

// --------------------
// Latest measurement per child per month
// --------------------
$sql = "
  SELECT
    MONTH(m.date_measured) AS mon,
    SUM(CASE WHEN $normalExpr THEN 1 ELSE 0 END) AS normal_count,
    SUM(CASE WHEN $normalExpr THEN 0 ELSE 1 END) AS mal_count
  FROM tbl_measurement m
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq

  JOIN (
    SELECT 
      child_seq, 
      MONTH(date_measured) AS mon, 
      MAX(date_measured) AS max_date
    FROM tbl_measurement
    WHERE YEAR(date_measured) = ?
    GROUP BY child_seq, MONTH(date_measured)
  ) lm
    ON lm.child_seq = m.child_seq
   AND lm.mon = MONTH(m.date_measured)
   AND lm.max_date = m.date_measured

  JOIN (
    SELECT 
      child_seq, 
      date_measured, 
      MAX(measure_id) AS max_measure_id
    FROM tbl_measurement
    WHERE YEAR(date_measured) = ?
    GROUP BY child_seq, date_measured
  ) lt
    ON lt.child_seq = m.child_seq
   AND lt.date_measured = m.date_measured
   AND lt.max_measure_id = m.measure_id

  WHERE YEAR(m.date_measured) = ?
  $scopeWhere

  GROUP BY MONTH(m.date_measured)
  ORDER BY MONTH(m.date_measured)
";

$params = [$year, $year, $year, ...$scopeParams];

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$normal = array_fill(0, 12, 0);
$mal = array_fill(0, 12, 0);

foreach ($rows as $r) {
  $idx = (int)$r["mon"] - 1;

  if ($idx >= 0 && $idx < 12) {
    $normal[$idx] = (int)$r["normal_count"];
    $mal[$idx] = (int)$r["mal_count"];
  }
}

echo json_encode([
  "ok" => true,
  "year" => $year,
  "barangay_id" => $filterBarangayId,
  "labels" => monthLabels(),
  "series" => [
    ["name" => "Normal", "data" => $normal],
    ["name" => "Malnourished", "data" => $mal]
  ]
]);