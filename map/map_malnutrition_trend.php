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

$barangayId = (int)($_GET['barangay_id'] ?? 0);
$barangayCode = strtoupper(preg_replace('/\s+/u', '', trim($_GET['barangay_code'] ?? '')));
$indicator = trim($_GET['indicator'] ?? 'wfa');
$status = trim($_GET['status'] ?? 'underweight');
$groupBy = trim($_GET['group_by'] ?? 'year');
$year = (int)($_GET['year'] ?? date('Y'));

$indicatorMap = [
  'wfa' => 'weight_status',
  'hfa' => 'height_status',
  'wfh' => 'lt_status',
  'muac' => 'muac_status'
];

if (!isset($indicatorMap[$indicator])) { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'Invalid indicator']); exit; }
if (!in_array($groupBy, ['year', 'month'], true)) { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'Invalid group_by']); exit; }

$column = $indicatorMap[$indicator];

function tableExists(PDO $pdo, string $table): bool {
  try { $st = $pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetchColumn(); }
  catch (Throwable $e) { return false; }
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

  if ($status === 'underweight') return ["(LOWER(m.$column) LIKE ? AND NOT $severeUnderweight)", ['%underweight%']];
  if ($status === 'severely_underweight') return ["$severeUnderweight", []];
  if ($status === 'stunted') return ["((LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?) AND NOT $severeStunted)", ['%stunted%', '%student%']];
  if ($status === 'severely_stunted') return ["$severeStunted", []];
  if ($status === 'wasted') return ["(LOWER(m.$column) LIKE ? AND NOT $severeWasted)", ['%wasted%']];
  if ($status === 'severely_wasted') return ["$severeWasted", []];
  if ($status === 'overweight') return ["(LOWER(m.$column) LIKE ? AND LOWER(m.$column) NOT LIKE ?)", ['%overweight%', '%obese%']];
  if ($status === 'obese') return ["LOWER(m.$column) LIKE ?", ['%obese%']];
  if ($status === 'mam_yellow') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%mam%', '%yellow%']];
  if ($status === 'sam_red') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%sam%', '%red%']];

  return ["LOWER(m.$column) LIKE ?", ['%' . $status . '%']];
}

$barangay = null;
if ($barangayId > 0) {
  $st = $pdo->prepare("SELECT barangay_id, barangay_name, barangay_code FROM tbl_barangay WHERE barangay_id = ? LIMIT 1");
  $st->execute([$barangayId]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$barangay && $barangayCode !== '') {
  $st = $pdo->prepare("SELECT barangay_id, barangay_name, barangay_code FROM tbl_barangay WHERE UPPER(REPLACE(REPLACE(COALESCE(barangay_code,''), ' ', ''), CHAR(160), '')) = ? LIMIT 1");
  $st->execute([$barangayCode]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$barangay) { http_response_code(404); echo json_encode(['ok' => false, 'message' => 'Barangay not found']); exit; }
$barangayId = (int)$barangay['barangay_id'];

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $assignedBarangayId = (int)($st->fetchColumn() ?: 0);
  if ($assignedBarangayId <= 0 || $assignedBarangayId !== $barangayId) {
    http_response_code(403); echo json_encode(['ok' => false, 'message' => 'You are not allowed to access this barangay']); exit;
  }
}

[$statusWhere, $statusParams] = statusCondition($column, $indicator, $status);

if ($groupBy === 'month') {
  $sql = "
    SELECT
      MONTH(m.date_measured) AS period_no,
      COUNT(DISTINCT CASE WHEN $statusWhere THEN m.child_seq END) AS cases
    FROM $measurementTable m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE ci.barangay_id = ?
      AND YEAR(m.date_measured) = ?
    GROUP BY MONTH(m.date_measured)
    ORDER BY period_no ASC
  ";
  $params = array_merge($statusParams, [$barangayId, $year]);
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $found = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $found[(int)$r['period_no']] = (int)$r['cases'];
  }
  $labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  $series = [];
  for ($i = 1; $i <= 12; $i++) {
    $series[] = [
      'period' => $labels[$i - 1],
      'period_no' => $i,
      'cases' => $found[$i] ?? 0
    ];
  }
} else {
  $sql = "
    SELECT
      YEAR(m.date_measured) AS period_no,
      COUNT(DISTINCT CASE WHEN $statusWhere THEN m.child_seq END) AS cases
    FROM $measurementTable m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE ci.barangay_id = ?
    GROUP BY YEAR(m.date_measured)
    ORDER BY period_no ASC
  ";
  $params = array_merge($statusParams, [$barangayId]);
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $series = array_map(fn($r) => [
    'period' => (string)$r['period_no'],
    'period_no' => (int)$r['period_no'],
    'cases' => (int)$r['cases']
  ], $st->fetchAll(PDO::FETCH_ASSOC));
}

$yearsStmt = $pdo->prepare("SELECT DISTINCT YEAR(m.date_measured) AS y FROM $measurementTable m JOIN tbl_child_info ci ON ci.child_seq = m.child_seq WHERE ci.barangay_id = ? AND m.date_measured IS NOT NULL ORDER BY y DESC");
$yearsStmt->execute([$barangayId]);
$years = array_values(array_filter(array_map('intval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN))));
if (!$years) $years = [(int)date('Y')];

echo json_encode([
  'ok' => true,
  'barangay_id' => $barangayId,
  'barangay_name' => $barangay['barangay_name'],
  'indicator' => $indicator,
  'status' => $status,
  'group_by' => $groupBy,
  'year' => $year,
  'years' => $years,
  'series' => $series
]);
