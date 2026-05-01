<?php
ob_start();
session_start();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: private, max-age=60");

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
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

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

$measurementTable = tableExists($pdo, 'tbl_measurement') ? 'tbl_measurement' : 'tbl_measurement';

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
  if ($status === 'severely_underweight') return [$severeUnderweight, []];
  if ($status === 'stunted') return ["((LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?) AND NOT $severeStunted)", ['%stunted%', '%student%']];
  if ($status === 'severely_stunted') return [$severeStunted, []];
  if ($status === 'wasted') return ["(LOWER(m.$column) LIKE ? AND NOT $severeWasted)", ['%wasted%']];
  if ($status === 'severely_wasted') return [$severeWasted, []];
  if ($status === 'overweight') return ["(LOWER(m.$column) LIKE ? AND LOWER(m.$column) NOT LIKE ?)", ['%overweight%', '%obese%']];
  if ($status === 'obese') return ["LOWER(m.$column) LIKE ?", ['%obese%']];
  if ($status === 'mam_yellow') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%mam%', '%yellow%']];
  if ($status === 'sam_red') return ["(LOWER(m.$column) LIKE ? OR LOWER(m.$column) LIKE ?)", ['%sam%', '%red%']];

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

$startDate = sprintf('%04d-01-01', $year);
$endDate = sprintf('%04d-01-01', $year + 1);

$sql = "
  SELECT
    b.barangay_id,
    b.barangay_name,
    b.barangay_code,
    COALESCE(agg.cases, 0) AS cases,
    COALESCE(agg.labels, '') AS labels,
    COALESCE(agg.measured_children, 0) AS measured_children,
    agg.last_measurement_date
  FROM tbl_barangay b
  LEFT JOIN (
    SELECT
      x.barangay_id,
      COUNT(DISTINCT x.child_seq) AS measured_children,
      COUNT(DISTINCT CASE WHEN x.is_case = 1 THEN x.child_seq END) AS cases,
      GROUP_CONCAT(DISTINCT CASE WHEN x.is_case = 1 THEN x.status_value END ORDER BY x.status_value SEPARATOR ', ') AS labels,
      MAX(x.date_measured) AS last_measurement_date
    FROM (
      SELECT
        ci.barangay_id,
        m.child_seq,
        m.$column AS status_value,
        m.date_measured,
        CASE WHEN $statusWhere THEN 1 ELSE 0 END AS is_case
      FROM $measurementTable m
      INNER JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
      WHERE m.date_measured >= ?
        AND m.date_measured < ?
        AND m.$column IS NOT NULL
        AND TRIM(m.$column) <> ''
    ) x
    GROUP BY x.barangay_id
  ) agg ON agg.barangay_id = b.barangay_id
  WHERE 1 = 1 $scopeWhere
  ORDER BY b.barangay_name ASC
";

$params = array_merge($statusParams, [$startDate, $endDate], $scopeParams);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
  $r['barangay_id'] = (int)$r['barangay_id'];
  $r['cases'] = (int)$r['cases'];
  $r['labels'] = $r['labels'] ?: '';
  $r['measured_children'] = (int)$r['measured_children'];
  $r['rate'] = $r['measured_children'] > 0 ? round(($r['cases'] / $r['measured_children']) * 100, 2) : 0;
}
unset($r);

$yearsSql = "
  SELECT DISTINCT YEAR(m.date_measured) AS y
  FROM $measurementTable m
  INNER JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  INNER JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
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
