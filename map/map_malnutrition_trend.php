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
$indicator = trim($_GET['indicator'] ?? 'wfa'); // wfa, hfa, wfh, muac
$status = trim($_GET['status'] ?? 'underweight');
$groupBy = trim($_GET['group_by'] ?? 'year'); // year or month
$year = (int)($_GET['year'] ?? date('Y'));

$indicatorMap = [
  'wfa' => 'weight_status',
  'hfa' => 'height_status',
  'wfh' => 'lt_status',
  'muac' => 'muac_status'
];
if (!isset($indicatorMap[$indicator])) { http_response_code(400); echo json_encode(['message' => 'Invalid indicator']); exit; }
if (!in_array($groupBy, ['year', 'month'], true)) { http_response_code(400); echo json_encode(['message' => 'Invalid group_by']); exit; }

$column = $indicatorMap[$indicator];

function statusWhere(string $column, string $status): array {
  $s = strtolower(trim($status));
  if ($s === 'underweight') return ["LOWER(m.$column) LIKE ?", ['%underweight%']];
  if ($s === 'stunted') return ["LOWER(m.$column) LIKE ?", ['%stunted%']];
  if ($s === 'wasted') return ["LOWER(m.$column) LIKE ?", ['%wasted%']];
  if ($s === 'overweight_obese') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%overweight%', '%obese%']];
  if ($s === 'mam') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%yellow%', '%mam%']];
  if ($s === 'sam') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%red%', '%sam%']];
  return ["LOWER(m.$column) LIKE ?", ['%' . $s . '%']];
}

$barangay = null;
if ($barangayId > 0) {
  $st = $pdo->prepare("SELECT barangay_id, barangay_name, barangay_code FROM tbl_barangay WHERE barangay_id = ? LIMIT 1");
  $st->execute([$barangayId]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$barangay && $barangayCode !== '') {
  $st = $pdo->prepare("SELECT barangay_id, barangay_name, barangay_code FROM tbl_barangay WHERE UPPER(REGEXP_REPLACE(COALESCE(barangay_code,''), '[[:space:]]+', '')) = ? LIMIT 1");
  $st->execute([$barangayCode]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$barangay) { http_response_code(404); echo json_encode(['message' => 'Barangay not found']); exit; }
$barangayId = (int)$barangay['barangay_id'];

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $assignedBarangayId = (int)($st->fetchColumn() ?: 0);
  if ($assignedBarangayId <= 0 || $assignedBarangayId !== $barangayId) {
    http_response_code(403); echo json_encode(['message' => 'You are not allowed to access this barangay']); exit;
  }
}

[$whereStatus, $statusParams] = statusWhere($column, $status);

if ($groupBy === 'month') {
  $sql = "
    SELECT MONTH(m.date_measured) AS period_no, COUNT(DISTINCT m.child_seq) AS total
    FROM tbl_measurement m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE ci.barangay_id = ?
      AND YEAR(m.date_measured) = ?
      AND $whereStatus
    GROUP BY MONTH(m.date_measured)
    ORDER BY period_no ASC
  ";
  $params = array_merge([$barangayId, $year], $statusParams);
  $st = $pdo->prepare($sql); $st->execute($params);
  $found = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $found[(int)$r['period_no']] = (int)$r['total'];
  $labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  $series = [];
  for ($i = 1; $i <= 12; $i++) $series[] = ['period' => $labels[$i-1], 'period_no' => $i, 'total' => $found[$i] ?? 0];
} else {
  $sql = "
    SELECT YEAR(m.date_measured) AS period_no, COUNT(DISTINCT m.child_seq) AS total
    FROM tbl_measurement m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE ci.barangay_id = ?
      AND $whereStatus
    GROUP BY YEAR(m.date_measured)
    ORDER BY period_no ASC
  ";
  $params = array_merge([$barangayId], $statusParams);
  $st = $pdo->prepare($sql); $st->execute($params);
  $series = array_map(fn($r) => ['period' => (string)$r['period_no'], 'period_no' => (int)$r['period_no'], 'total' => (int)$r['total']], $st->fetchAll(PDO::FETCH_ASSOC));
}

$yearsStmt = $pdo->prepare("SELECT DISTINCT YEAR(m.date_measured) AS y FROM tbl_measurement m JOIN tbl_child_info ci ON ci.child_seq = m.child_seq WHERE ci.barangay_id = ? AND m.date_measured IS NOT NULL ORDER BY y DESC");
$yearsStmt->execute([$barangayId]);
$years = array_values(array_filter(array_map('intval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN))));

echo json_encode([
  'barangay_id' => $barangayId,
  'barangay_name' => $barangay['barangay_name'],
  'indicator' => $indicator,
  'status' => $status,
  'group_by' => $groupBy,
  'year' => $year,
  'years' => $years,
  'series' => $series
]);
