<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

$barangayId = (int)($_GET['barangay_id'] ?? 0);
$barangayCode = strtoupper(preg_replace('/\s+/u', '', trim($_GET['barangay_code'] ?? '')));

if ($barangayId <= 0 && $barangayCode === '') {
  http_response_code(400);
  echo json_encode(["message" => "barangay_id or barangay_code is required"]);
  exit;
}

// --------------------
// Restrict BNS/user to their own barangay
// --------------------
$assignedBarangayId = 0;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $assignedBarangayId = (int)($st->fetchColumn() ?: 0);

  if ($assignedBarangayId <= 0) {
    http_response_code(403);
    echo json_encode(["message" => "No barangay assigned"]);
    exit;
  }
}

// --------------------
// Resolve requested barangay
// Prefer barangay_id, fallback to cleaned barangay_code
// --------------------
$barangay = null;

if ($barangayId > 0) {
  $st = $pdo->prepare("
    SELECT barangay_id, barangay_name, barangay_code
    FROM tbl_barangay
    WHERE barangay_id = ?
    LIMIT 1
  ");
  $st->execute([$barangayId]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$barangay && $barangayCode !== '') {
  $sqlBarangay = "
    SELECT barangay_id, barangay_name, barangay_code
    FROM tbl_barangay
    WHERE UPPER(REGEXP_REPLACE(COALESCE(barangay_code,''), '[[:space:]]+', '')) = ?
    LIMIT 1
  ";
  $st = $pdo->prepare($sqlBarangay);
  $st->execute([$barangayCode]);
  $barangay = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$barangay) {
  http_response_code(404);
  echo json_encode([
    "message" => "Barangay not found",
    "debug" => [
      "barangay_id" => $barangayId,
      "barangay_code" => $barangayCode
    ]
  ]);
  exit;
}

$barangayId = (int)$barangay['barangay_id'];

if ($role !== 'admin' && $barangayId !== $assignedBarangayId) {
  http_response_code(403);
  echo json_encode(["message" => "You are not allowed to access this barangay"]);
  exit;
}

// --------------------
// Latest measurement per child with tie-breaker
// --------------------
$sql = "
  SELECT
    ci.child_seq,
    ci.c_firstname,
    ci.c_middlename,
    ci.c_lastname,
    ci.sex,
    m.date_measured,
    m.weight_status,
    m.height_status,
    m.lt_status,
    m.muac_status
  FROM tbl_child_info ci
  LEFT JOIN (
    SELECT m1.*
    FROM tbl_measurement m1
    JOIN (
      SELECT child_seq, MAX(date_measured) AS max_date
      FROM tbl_measurement
      GROUP BY child_seq
    ) lm
      ON lm.child_seq = m1.child_seq
     AND lm.max_date = m1.date_measured
    JOIN (
      SELECT child_seq, date_measured, MAX(measure_id) AS max_measure_id
      FROM tbl_measurement
      GROUP BY child_seq, date_measured
    ) lt
      ON lt.child_seq = m1.child_seq
     AND lt.date_measured = m1.date_measured
     AND lt.max_measure_id = m1.measure_id
  ) m ON m.child_seq = ci.child_seq
  WHERE ci.barangay_id = ?
  ORDER BY ci.c_lastname ASC, ci.c_firstname ASC, ci.c_middlename ASC
";

$st = $pdo->prepare($sql);
$st->execute([$barangayId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$out = [];

foreach ($rows as $r) {
  $lastDate = $r['date_measured'] ?? null;

  $weight = strtolower(trim((string)($r['weight_status'] ?? '')));
  $height = strtolower(trim((string)($r['height_status'] ?? '')));
  $lt = strtolower(trim((string)($r['lt_status'] ?? '')));
  $muac = strtolower(trim((string)($r['muac_status'] ?? '')));

  $isOverdue = !$lastDate || strtotime($lastDate) < strtotime('-90 days');

  $isHighRisk =
    in_array($weight, ['underweight', 'severely underweight'], true) ||
    in_array($height, ['stunted', 'severely stunted'], true) ||
    in_array($lt, ['wasted', 'severely wasted', 'overweight', 'obese'], true) ||
    str_contains($muac, 'yellow') ||
    str_contains($muac, 'red') ||
    str_contains($muac, 'mam') ||
    str_contains($muac, 'sam');

  $statusTag = $isHighRisk ? 'high-risk' : ($isOverdue ? 'overdue' : 'normal');

  $out[] = [
    "child_seq" => (int)$r['child_seq'],
    "child_name" => trim(implode(' ', array_filter([
      $r['c_firstname'] ?? '',
      $r['c_middlename'] ?? '',
      $r['c_lastname'] ?? ''
    ]))),
    "sex" => $r['sex'] ?? '',
    "date_measured" => $lastDate,
    "weight_status" => $r['weight_status'] ?? '',
    "height_status" => $r['height_status'] ?? '',
    "lt_status" => $r['lt_status'] ?? '',
    "muac_status" => $r['muac_status'] ?? '',
    "is_overdue" => $isOverdue,
    "is_high_risk" => $isHighRisk,
    "status_tag" => $statusTag
  ];
}

echo json_encode([
  "barangay_id" => $barangayId,
  "barangay_name" => $barangay['barangay_name'],
  "barangay_code" => strtoupper(preg_replace('/\s+/u', '', (string)($barangay['barangay_code'] ?? ''))),
  "children" => $out
]);