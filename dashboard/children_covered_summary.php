<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS
// --------------------
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

// --------------------
// Scope: Admin = all; BNS = their barangay only
// --------------------
$scopeWhere = "";
$scopeParams = [];

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    echo json_encode([
      "ok" => true,
      "data" => [
        "undernutrition_0_59" => 0,
        "overweight_obese_0_59" => 0,
        "total_0_23" => 0,
        "undernutrition_0_23" => 0,
        "basis" => "latest_measurement_per_child"
      ]
    ]);
    exit;
  }

  $scopeWhere = " AND ci.barangay_id = ? ";
  $scopeParams[] = $barangayId;
}

// --------------------
// Latest measurement per child (global latest)
// 1) max date per child
// 2) if tie, max measure_id on that date
// --------------------
$latestJoin = "
  JOIN (
    SELECT child_seq, MAX(date_measured) AS max_date
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = m.child_seq
      AND lm.max_date = m.date_measured
";

$latestTie = "
  JOIN (
    SELECT child_seq, date_measured, MAX(measure_id) AS max_measure_id
    FROM tbl_measurement
    GROUP BY child_seq, date_measured
  ) lt ON lt.child_seq = m.child_seq
      AND lt.date_measured = m.date_measured
      AND lt.max_measure_id = m.measure_id
";

// --------------------
// Definitions
// IMPORTANT: You can tweak these depending on your final rules.
// --------------------

// "Undernutrition" if ANY of these indicate undernutrition:
$undernutritionExpr = "
  (
    LOWER(COALESCE(m.weight_status,'')) IN ('underweight','severely underweight')
    OR LOWER(COALESCE(m.height_status,'')) IN ('stunted','severely stunted')
    OR LOWER(COALESCE(m.lt_status,'')) IN ('wasted','severely wasted')
    OR LOWER(COALESCE(m.muac_status,'')) LIKE '%yellow%'
    OR LOWER(COALESCE(m.muac_status,'')) LIKE '%red%'
    OR LOWER(COALESCE(m.muac_status,'')) LIKE '%mam%'
    OR LOWER(COALESCE(m.muac_status,'')) LIKE '%sam%'
  )
";

// "Overweight/Obese"
$overExpr = "
  (
    LOWER(COALESCE(m.weight_status,'')) IN ('overweight','obese')
    OR LOWER(COALESCE(m.lt_status,'')) IN ('overweight','obese')
  )
";

// --------------------
// Query: count based on latest measurement per child
// Uses age_months from tbl_measurement
// --------------------
$sql = "
  SELECT
    SUM(CASE WHEN m.age_months BETWEEN 0 AND 59 AND ($undernutritionExpr) THEN 1 ELSE 0 END) AS undernutrition_0_59,
    SUM(CASE WHEN m.age_months BETWEEN 0 AND 59 AND ($overExpr) THEN 1 ELSE 0 END) AS overweight_obese_0_59,
    SUM(CASE WHEN m.age_months BETWEEN 0 AND 23 THEN 1 ELSE 0 END) AS total_0_23,
    SUM(CASE WHEN m.age_months BETWEEN 0 AND 23 AND ($undernutritionExpr) THEN 1 ELSE 0 END) AS undernutrition_0_23
  FROM tbl_measurement m
  $latestJoin
  $latestTie
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  WHERE 1=1
  $scopeWhere
";

$st = $pdo->prepare($sql);
$st->execute($scopeParams);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
  "ok" => true,
  "data" => [
    "undernutrition_0_59" => (int)($row["undernutrition_0_59"] ?? 0),
    "overweight_obese_0_59" => (int)($row["overweight_obese_0_59"] ?? 0),
    "total_0_23" => (int)($row["total_0_23"] ?? 0),
    "undernutrition_0_23" => (int)($row["undernutrition_0_23"] ?? 0),
    "basis" => "latest_measurement_per_child"
  ]
]);