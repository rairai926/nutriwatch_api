<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

// --------------------
// Params
// --------------------
$range = trim((string)($_GET['range'] ?? 'month')); // month|7days|today

$allowedRange = ['month', '7days', 'today'];
if (!in_array($range, $allowedRange, true)) {
  $range = 'month';
}

// --------------------
// Restrict for non-admin to their barangay
// --------------------
$userBarangayId = 0;

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $userBarangayId = (int)($st->fetchColumn() ?: 0);

  if ($userBarangayId <= 0) {
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "message" => "No barangay assigned"
    ]);
    exit;
  }
}

// --------------------
// Date range
// IMPORTANT: change ci.date_added if needed
// --------------------
if ($range === 'today') {
  $dateFrom = date('Y-m-d 00:00:00');
  $dateTo   = date('Y-m-d 00:00:00', strtotime('+1 day'));
} elseif ($range === '7days') {
  $dateFrom = date('Y-m-d 00:00:00', strtotime('-6 days'));
  $dateTo   = date('Y-m-d 00:00:00', strtotime('+1 day'));
} else {
  $dateFrom = date('Y-m-01 00:00:00');
  $dateTo   = date('Y-m-01 00:00:00', strtotime('+1 month'));
}

// --------------------
// WHERE
// --------------------
$where = [];
$params = [];

if ($role !== 'admin') {
  $where[] = "ci.barangay_id = ?";
  $params[] = $userBarangayId;
}

$where[] = "ci.date_added IS NOT NULL";
$where[] = "ci.date_added >= ?";
$params[] = $dateFrom;

$where[] = "ci.date_added < ?";
$params[] = $dateTo;

$whereSql = "WHERE " . implode(" AND ", $where);

// --------------------
// COUNT ONLY
// --------------------
$sql = "
  SELECT COUNT(*) AS total
  FROM tbl_child_info ci
  $whereSql
";

$st = $pdo->prepare($sql);
$st->execute($params);
$total = (int)$st->fetchColumn();

// --------------------
// RESPONSE
// --------------------
echo json_encode([
  "ok" => true,
  "range" => $range,
  "total" => $total
]);