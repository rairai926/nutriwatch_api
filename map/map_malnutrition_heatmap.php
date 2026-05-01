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

$measurementTable = tableExists($pdo, 'tbl_measurement') ? 'tbl_measurement' : 'tbl_mesurement';

function likeAny(string $column, array $patterns): array {
  $parts = [];
  $params = [];
  foreach ($patterns as $p) {
    $parts[] = "LOWER(m.$column) LIKE ?";
    $params[] = $p;
  }
  return ['(' . implode(' OR ', $parts) . ')', $params];
}

function statusCondition(string $column, string $indicator, string $status): array {
  $status = strtolower(trim($status));

  $severeUnderweight = "(LOWER(m.$column) LIKE '%severely underweight%' OR LOWER(m.$column) LIKE '%severe underweight%')";
  $severeStunted = "(LOWER(m.$column) LIKE '%severely stunted%' OR LOWER(m.$column) LIKE '%severe stunted%' OR LOWER(m.$column) LIKE '%severely student%' OR LOWER(m.$column) LIKE '%severe student%')";
  $severeWasted = "(LOWER(m.$column) LIKE '%severely wasted%' OR LOWER(m.$column) LIKE '%severe wasted%')";

  if ($status === 'all_malnutrition') {
    if ($indicator === 'wfa') return likeAny($column, ['%underweight%']);
    if ($indicator === 'hfa') return likeAny($column, ['%stunted%', '%student%']);
    if ($indicator === 'wfh') return likeAny($column, ['%wasted%', '%overweight%', '%obese%']);
    if ($indicator === 'muac') return likeAny($column, ['%mam%', '%yellow%', '%sam%', '%red%']);
  }

  if ($status === 'underweight') {
    return ["(LOWER(m.$column) LIKE ? AND NOT $severeUnderweight)", ['%underweight%']];
  }
  if ($status === 'severely_underweight') {
    return ["$severeUnderweight", []];
  }
  if ($status === 'stunted') {
    return ["((LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?) AND NOT $severeStunted)", ['%stunted%', '%student%']];
  }
  if ($status === 'severely_stunted') {
    return ["$severeStunted", []];
  }
  if ($status === 'wasted') {
    return ["(LOWER(m.$column) LIKE ? AND NOT $severeWasted)", ['%wasted%']];
  }
  if ($status === 'severely_wasted') {
    return ["$severeWasted", []];
  }
  if ($status === 'overweight') {
    return ["(LOWER(m.$column) LIKE ? AND LOWER(m.$column) NOT LIKE ?)", ['%overweight%', '%obese%']];
  }
  if ($status === 'obese') {
    return ["LOWER(m.$column) LIKE ?", ['%obese%']];
  }
  if ($status === 'mam_yellow') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%mam%', '%yellow%']];
  }
  if ($status === 'sam_red') {
    return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%sam%', '%red%']];
  }

  return ["LOWER(m.$column) LIKE ?", ['%' . $status . '%']];
}

function userBarangayConstraint(PDO $pdo, string $role, int $userId): array {
  if ($role === 'admin') return ['', []];

  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) return [' AND 1 = 0 ', []];
  return [' AND b.barangay_id = ? ', [$barangayId]];
}

[$statusWhere, $statusParams] = statusCondition($column, $indicator, $status);
[$scopeWhere, $scopeParams] = userBarangayConstraint($pdo, $role, $userId);

$sql = "
  SELECT
    b.barangay_id,
    b.barangay_name,
    b.barangay_code,
    COUNT(DISTINCT CASE WHEN $statusWhere THEN m.child_seq END) AS cases,
    GROUP_CONCAT(DISTINCT CASE WHEN $statusWhere THEN m.$column END ORDER BY m.$column SEPARATOR ', ') AS labels,
    COUNT(DISTINCT m.child_seq) AS measured_children,
    MAX(m.date_measured) AS last_measurement_date
  FROM tbl_barangay b
  LEFT JOIN tbl_child_info ci ON ci.barangay_id = b.barangay_id
  LEFT JOIN $measurementTable m ON m.child_seq = ci.child_seq AND YEAR(m.date_measured) = ?
  WHERE 1 = 1 $scopeWhere
  GROUP BY b.barangay_id, b.barangay_name, b.barangay_code
  ORDER BY b.barangay_name ASC
";

$params = array_merge($statusParams, $statusParams, [$year], $scopeParams);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
  $r['barangay_id'] = (int)$r['barangay_id'];
  $r['cases'] = (int)$r['cases'];
  $r['labels'] = $r['labels'] ?: '';
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
$yst = $pdo->prepare($yearsSql);
$yst->execute($scopeParams);
$years = array_values(array_filter(array_map('intval', $yst->fetchAll(PDO::FETCH_COLUMN))));
if (!$years) $years = [(int)date('Y')];

echo json_encode([
  'ok' => true,
  'indicator' => $indicator,
  'status' => $status,
  'year' => $year,
  'years' => $years,
  'rows' => $rows
]);
