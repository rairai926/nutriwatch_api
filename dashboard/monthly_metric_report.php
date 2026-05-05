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

$authUser = authenticate(['admin','user','bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

function monthLabels() {
  return ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
}

$metric = trim($_GET["metric"] ?? "lt_status");
$year = (int)($_GET["year"] ?? date("Y"));
$filterBarangayId = (int)($_GET["barangay_id"] ?? 0);

// --------------------
// STATUS DEFINITIONS
// --------------------
function metricStatusList($metric) {
  if ($metric === 'weight_status') {
    return [
      ['name'=>'Normal','patterns'=>['%normal%'],'exclude'=>[]],
      ['name'=>'Underweight','patterns'=>['%underweight%'],'exclude'=>['%severely underweight%']],
      ['name'=>'Severely Underweight','patterns'=>['%severely underweight%'],'exclude'=>[]]
    ];
  }

  if ($metric === 'height_status') {
    return [
      ['name'=>'Normal','patterns'=>['%normal%'],'exclude'=>[]],
      ['name'=>'Stunted','patterns'=>['%stunted%'],'exclude'=>['%severely stunted%']],
      ['name'=>'Severely Stunted','patterns'=>['%severely stunted%'],'exclude'=>[]]
    ];
  }

  if ($metric === 'lt_status') {
    return [
      ['name'=>'Normal','patterns'=>['%normal%'],'exclude'=>[]],
      ['name'=>'Wasted','patterns'=>['%wasted%'],'exclude'=>['%severely wasted%']],
      ['name'=>'Severely Wasted','patterns'=>['%severely wasted%'],'exclude'=>[]],
      ['name'=>'Overweight','patterns'=>['%overweight%'],'exclude'=>['%obese%']],
      ['name'=>'Obese','patterns'=>['%obese%'],'exclude'=>[]]
    ];
  }

  return [
    ['name'=>'Normal / Green','patterns'=>['%green%','%normal%'],'exclude'=>[]],
    ['name'=>'MAM / Yellow','patterns'=>['%yellow%','%mam%'],'exclude'=>[]],
    ['name'=>'SAM / Red','patterns'=>['%red%','%sam%'],'exclude'=>[]]
  ];
}

// --------------------
// BARANGAY SCOPE
// --------------------
$scopeWhere = "";
$scopeParams = [];

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $assignedBarangayId = (int)($st->fetchColumn() ?: 0);

  if ($assignedBarangayId <= 0) {
    echo json_encode([
      "ok"=>true,
      "labels"=>monthLabels(),
      "series"=>[]
    ]);
    exit;
  }

  $scopeWhere = " AND ci.barangay_id = ? ";
  $scopeParams[] = $assignedBarangayId;
} else {
  if ($filterBarangayId > 0) {
    $scopeWhere = " AND ci.barangay_id = ? ";
    $scopeParams[] = $filterBarangayId;
  }
}

// --------------------
// BUILD SERIES
// --------------------
$labels = monthLabels();
$series = [];

foreach (metricStatusList($metric) as $def) {

  $conditions = [];
  $params = [];

  foreach ($def['patterns'] as $p) {
    $conditions[] = "LOWER(m.$metric) LIKE ?";
    $params[] = $p;
  }

  $sqlWhere = "(" . implode(" OR ", $conditions) . ")";

  foreach ($def['exclude'] as $ex) {
    $sqlWhere .= " AND LOWER(m.$metric) NOT LIKE ?";
    $params[] = $ex;
  }

  $sql = "
    SELECT
      MONTH(m.date_measured) AS mon,
      COUNT(DISTINCT m.child_seq) AS cnt
    FROM tbl_measurement m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE YEAR(m.date_measured)=?
      $scopeWhere
      AND $sqlWhere
    GROUP BY MONTH(m.date_measured)
  ";

  $finalParams = array_merge([$year], $scopeParams, $params);

  $st = $pdo->prepare($sql);
  $st->execute($finalParams);

  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $map[(int)$r['mon']] = (int)$r['cnt'];
  }

  $data = [];
  for ($i=1;$i<=12;$i++) {
    $data[] = $map[$i] ?? 0;
  }

  $series[] = [
    "name"=>$def['name'],
    "data"=>$data
  ];
}

echo json_encode([
  "ok"=>true,
  "year"=>$year,
  "labels"=>$labels,
  "series"=>$series
]);