<?php
ob_start();
session_start();
header("Content-Type: application/json; charset=utf-8");

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
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") { http_response_code(200); exit; }

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

$indicator = trim($_GET['indicator'] ?? 'wfa');
$status = trim($_GET['status'] ?? 'underweight');
$year = (int)($_GET['year'] ?? date('Y'));

$indicatorMap = [
  'wfa' => 'weight_status',
  'hfa' => 'height_status',
  'wfh' => 'lt_status',
  'muac' => 'muac_status'
];

if (!isset($indicatorMap[$indicator])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid indicator']);
  exit;
}

$column = $indicatorMap[$indicator];

function tableExists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

// Use tbl_measurement by default, but support the common typo tbl_measurement if your database uses it.
$measurementTable = tableExists($pdo, 'tbl_measurement') ? 'tbl_measurement' : 'tbl_measurement';

function statusCondition(string $column, string $status): array {
  $s = strtolower(trim($status));

  if ($s === 'underweight') {
    return ["LOWER(m.$column) LIKE ?", ['%underweight%']];
  }
  if ($s === 'stunted') {
    return ["LOWER(m.$column) LIKE ?", ['%stunted%']];
  }
  if ($s === 'wasted') {
    return ["LOWER(m.$column) LIKE ?", ['%wasted%']];
  }
  if ($s === 'overweight_obese') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%overweight%', '%obese%']];
  }
  if ($s === 'mam') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%mam%', '%yellow%']];
  }
  if ($s === 'sam') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%sam%', '%red%']];
  }
  if ($s === 'mam_sam') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%mam%', '%yellow%', '%sam%', '%red%']];
  }

  return ["LOWER(m.$column) LIKE ?", ['%' . $s . '%']];
}

function severeCondition(string $column, string $status): array {
  $s = strtolower(trim($status));

  if ($s === 'underweight') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%severely underweight%', '%severe underweight%']];
  }
  if ($s === 'stunted') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%severely stunted%', '%severe stunted%']];
  }
  if ($s === 'wasted') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%severely wasted%', '%severe wasted%']];
  }
  if ($s === 'overweight_obese') {
    // Treat obese as the more severe excess-weight classification and overweight as moderate.
    return ["LOWER(m.$column) LIKE ?", ['%obese%']];
  }
  if ($s === 'sam' || $s === 'mam_sam') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%sam%', '%red%', '%severe%']];
  }

  // MAM is moderate only.
  return ["1 = 0", []];
}

function userBarangayConstraint(PDO $pdo, string $role, int $userId): array {
  if ($role === 'admin') return ['', []];

  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    return [' AND 1 = 0 ', []];
  }

  return [' AND b.barangay_id = ? ', [$barangayId]];
}

[$statusWhere, $statusParams] = statusCondition($column, $status);
[$severeWhere, $severeParams] = severeCondition($column, $status);
[$scopeWhere, $scopeParams] = userBarangayConstraint($pdo, $role, $userId);

$sql = "
  SELECT
    b.barangay_id,
    b.barangay_name,
    b.barangay_code,
    COUNT(DISTINCT CASE WHEN $statusWhere AND NOT ($severeWhere) THEN m.child_seq END) AS moderate_cases,
    COUNT(DISTINCT CASE WHEN $statusWhere AND ($severeWhere) THEN m.child_seq END) AS severe_cases,
    COUNT(DISTINCT m.child_seq) AS measured_children,
    MAX(m.date_measured) AS last_measurement_date
  FROM tbl_barangay b
  LEFT JOIN tbl_child_info ci ON ci.barangay_id = b.barangay_id
  LEFT JOIN $measurementTable m ON m.child_seq = ci.child_seq AND YEAR(m.date_measured) = ?
  WHERE 1 = 1 $scopeWhere
  GROUP BY b.barangay_id, b.barangay_name, b.barangay_code
  ORDER BY b.barangay_name ASC
";

$params = [];
$params = array_merge($params, $statusParams, $severeParams, $statusParams, $severeParams, [$year], $scopeParams);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
  $r['barangay_id'] = (int)$r['barangay_id'];
  $r['moderate_cases'] = (int)$r['moderate_cases'];
  $r['severe_cases'] = (int)$r['severe_cases'];
  $r['total_cases'] = $r['moderate_cases'] + $r['severe_cases'];
  $r['weighted_cases'] = $r['moderate_cases'] + ($r['severe_cases'] * 2);
  $r['measured_children'] = (int)$r['measured_children'];
}
unset($r);

$yearsSql = "
  SELECT DISTINCT YEAR(m.date_measured) AS y
  FROM $measurementTable m
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
  WHERE m.date_measured IS NOT NULL $scopeWhere
  ORDER BY y DESC
";
$yearsParams = $scopeParams;
$yst = $pdo->prepare($yearsSql);
$yst->execute($yearsParams);
$years = array_values(array_filter(array_map('intval', $yst->fetchAll(PDO::FETCH_COLUMN))));

if (!$years) $years = [(int)date('Y')];

$response = [
  'ok' => true,
  'indicator' => $indicator,
  'status' => $status,
  'year' => $year,
  'years' => $years,
  'rows' => $rows
];

echo json_encode($response);
