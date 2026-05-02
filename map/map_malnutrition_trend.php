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
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

$barangayId = (int)($_GET['barangay_id'] ?? 0);
$barangayCode = strtoupper(preg_replace('/\s+/u', '', trim($_GET['barangay_code'] ?? '')));
$indicator = trim($_GET['indicator'] ?? 'wfa');
$status = trim($_GET['status'] ?? 'all_malnutrition');
$groupBy = trim($_GET['group_by'] ?? 'year');
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

if (!in_array($groupBy, ['year', 'month'], true)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid group_by']);
  exit;
}

$column = $indicatorMap[$indicator];
$measurementTable = 'tbl_measurement';

function statusSeriesList(string $indicator, string $status): array {
  if ($status !== 'all_malnutrition') {
    return [singleStatusDef($status)];
  }

  if ($indicator === 'wfa') {
    return [
      singleStatusDef('underweight'),
      singleStatusDef('severely_underweight')
    ];
  }

  if ($indicator === 'hfa') {
    return [
      singleStatusDef('stunted'),
      singleStatusDef('severely_stunted')
    ];
  }

  if ($indicator === 'wfh') {
    return [
      singleStatusDef('wasted'),
      singleStatusDef('severely_wasted'),
      singleStatusDef('overweight'),
      singleStatusDef('obese')
    ];
  }

  if ($indicator === 'muac') {
    return [
      singleStatusDef('mam_yellow'),
      singleStatusDef('sam_red')
    ];
  }

  return [];
}

function singleStatusDef(string $status): array {
  $map = [
    'underweight' => [
      'key' => 'underweight',
      'label' => 'Underweight',
      'patterns' => ['%underweight%'],
      'exclude' => ['%severely underweight%', '%severe underweight%']
    ],
    'severely_underweight' => [
      'key' => 'severely_underweight',
      'label' => 'Severely Underweight',
      'patterns' => ['%severely underweight%', '%severe underweight%'],
      'exclude' => []
    ],
    'stunted' => [
      'key' => 'stunted',
      'label' => 'Stunted',
      'patterns' => ['%stunted%', '%student%'],
      'exclude' => ['%severely stunted%', '%severe stunted%', '%severely student%', '%severe student%']
    ],
    'severely_stunted' => [
      'key' => 'severely_stunted',
      'label' => 'Severely Stunted',
      'patterns' => ['%severely stunted%', '%severe stunted%', '%severely student%', '%severe student%'],
      'exclude' => []
    ],
    'wasted' => [
      'key' => 'wasted',
      'label' => 'Wasted',
      'patterns' => ['%wasted%'],
      'exclude' => ['%severely wasted%', '%severe wasted%']
    ],
    'severely_wasted' => [
      'key' => 'severely_wasted',
      'label' => 'Severely Wasted',
      'patterns' => ['%severely wasted%', '%severe wasted%'],
      'exclude' => []
    ],
    'overweight' => [
      'key' => 'overweight',
      'label' => 'Overweight',
      'patterns' => ['%overweight%'],
      'exclude' => ['%obese%']
    ],
    'obese' => [
      'key' => 'obese',
      'label' => 'Obese',
      'patterns' => ['%obese%'],
      'exclude' => []
    ],
    'mam_yellow' => [
      'key' => 'mam_yellow',
      'label' => 'MAM / Yellow',
      'patterns' => ['%mam%', '%yellow%'],
      'exclude' => []
    ],
    'sam_red' => [
      'key' => 'sam_red',
      'label' => 'SAM / Red',
      'patterns' => ['%sam%', '%red%'],
      'exclude' => []
    ]
  ];

  return $map[$status] ?? [
    'key' => $status,
    'label' => ucwords(str_replace('_', ' ', $status)),
    'patterns' => ['%' . strtolower($status) . '%'],
    'exclude' => []
  ];
}

function buildCondition(string $column, array $def): array {
  $where = [];
  $params = [];

  foreach ($def['patterns'] as $p) {
    $where[] = "LOWER(m.$column) LIKE ?";
    $params[] = $p;
  }

  $sql = '(' . implode(' OR ', $where) . ')';

  foreach ($def['exclude'] as $ex) {
    $sql .= " AND LOWER(m.$column) NOT LIKE ?";
    $params[] = $ex;
  }

  return [$sql, $params];
}

$barangay = null;

if ($barangayId > 0) {
  $st = $pdo->prepare("SELECT barangay_id, barangay_name, barangay_code FROM tbl_barangay WHERE barangay_id = ? LIMIT 1");
  $st->execute([$barangayId]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$barangay && $barangayCode !== '') {
  $st = $pdo->prepare("
    SELECT barangay_id, barangay_name, barangay_code
    FROM tbl_barangay
    WHERE UPPER(REPLACE(REPLACE(COALESCE(barangay_code,''), ' ', ''), CHAR(160), '')) = ?
    LIMIT 1
  ");
  $st->execute([$barangayCode]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$barangay) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'Barangay not found']);
  exit;
}

$barangayId = (int)$barangay['barangay_id'];

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $assignedBarangayId = (int)($st->fetchColumn() ?: 0);

  if ($assignedBarangayId <= 0 || $assignedBarangayId !== $barangayId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'You are not allowed to access this barangay']);
    exit;
  }
}

$statusDefs = statusSeriesList($indicator, $status);
$outputSeries = [];

foreach ($statusDefs as $def) {
  [$statusWhere, $statusParams] = buildCondition($column, $def);

  if ($groupBy === 'month') {
    $startDate = sprintf('%04d-01-01', $year);
    $endDate = sprintf('%04d-01-01', $year + 1);

    $sql = "
      SELECT
        MONTH(m.date_measured) AS period_no,
        COUNT(DISTINCT m.child_seq) AS cases
      FROM $measurementTable m
      JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
      WHERE ci.barangay_id = ?
        AND m.date_measured >= ?
        AND m.date_measured < ?
        AND $statusWhere
      GROUP BY MONTH(m.date_measured)
      ORDER BY period_no ASC
    ";

    $params = array_merge([$barangayId, $startDate, $endDate], $statusParams);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $found = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $found[(int)$r['period_no']] = (int)$r['cases'];
    }

    $data = [];
    for ($i = 1; $i <= 12; $i++) {
      $data[] = $found[$i] ?? 0;
    }

    $outputSeries[] = [
      'key' => $def['key'],
      'name' => $def['label'],
      'data' => $data
    ];
  } else {
    $sql = "
      SELECT
        YEAR(m.date_measured) AS period_no,
        COUNT(DISTINCT m.child_seq) AS cases
      FROM $measurementTable m
      JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
      WHERE ci.barangay_id = ?
        AND m.date_measured IS NOT NULL
        AND $statusWhere
      GROUP BY YEAR(m.date_measured)
      ORDER BY period_no ASC
    ";

    $params = array_merge([$barangayId], $statusParams);
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $dataMap = [];

    foreach ($rows as $r) {
      $dataMap[(int)$r['period_no']] = (int)$r['cases'];
    }

    $outputSeries[] = [
      'key' => $def['key'],
      'name' => $def['label'],
      'map' => $dataMap
    ];
  }
}

if ($groupBy === 'month') {
  $labels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
} else {
  $allYears = [];

  foreach ($outputSeries as $s) {
    foreach (($s['map'] ?? []) as $y => $_) {
      $allYears[(int)$y] = true;
    }
  }

  $labels = array_map('strval', array_keys($allYears));
  sort($labels);

  foreach ($outputSeries as &$s) {
    $data = [];
    foreach ($labels as $label) {
      $data[] = (int)($s['map'][(int)$label] ?? 0);
    }
    unset($s['map']);
    $s['data'] = $data;
  }
  unset($s);
}

$yearsStmt = $pdo->prepare("
  SELECT DISTINCT YEAR(m.date_measured) AS y
  FROM $measurementTable m
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  WHERE ci.barangay_id = ?
    AND m.date_measured IS NOT NULL
  ORDER BY y DESC
");
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
  'labels' => $labels,
  'series' => $outputSeries
]);