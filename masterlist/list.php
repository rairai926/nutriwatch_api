<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

// --------------------
// Params
// --------------------
$view = trim($_GET['view'] ?? 'all');
$allowedViews = ['all', 'updated', 'outdated', 'archive'];
if (!in_array($view, $allowedViews, true)) $view = 'all';

$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;
$offset = ($page - 1) * $limit;

$q = trim((string)($_GET['q'] ?? ''));
$sex = trim((string)($_GET['sex'] ?? ''));
$measurement = trim((string)($_GET['measurement'] ?? 'all')); // all|with|without
$schedule = trim((string)($_GET['schedule'] ?? 'all')); // all|monthly|quarterly
$sort = trim((string)($_GET['sort'] ?? 'latest')); // latest|oldest|name_asc|name_desc

$allowedMeasurement = ['all', 'with', 'without'];
if (!in_array($measurement, $allowedMeasurement, true)) $measurement = 'all';

$allowedSchedule = ['all', 'monthly', 'quarterly'];
if (!in_array($schedule, $allowedSchedule, true)) $schedule = 'all';

$allowedSort = ['latest', 'oldest', 'name_asc', 'name_desc'];
if (!in_array($sort, $allowedSort, true)) $sort = 'latest';

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
    echo json_encode(["ok" => false, "message" => "No barangay assigned"]);
    exit;
  }
}

// --------------------
// Month boundaries
// --------------------
$firstDay = date('Y-m-01');
$nextMonthFirstDay = date('Y-m-01', strtotime('+1 month', strtotime($firstDay)));

// --------------------
// ARCHIVE VIEW
// --------------------
if ($view === 'archive') {
  $where = [];
  $params = [];

  if ($role !== 'admin') {
    $where[] = "ca.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  if ($q !== '') {
    $where[] = "(
      CONCAT_WS(' ', ca.c_firstname, ca.c_middlename, ca.c_lastname) LIKE ?
      OR CONCAT_WS(' ', ca.g_firstname, ca.g_middlename, ca.g_lastname) LIKE ?
      OR COALESCE(ca.purok, '') LIKE ?
      OR COALESCE(b.barangay_name, '') LIKE ?
    )";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like);
  }

  if ($sex !== '') {
    $where[] = "LOWER(COALESCE(ca.sex, '')) = LOWER(?)";
    $params[] = $sex;
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

  $orderSql = "ORDER BY ca.archived_id DESC";
  if ($sort === 'name_asc') {
    $orderSql = "ORDER BY ca.c_lastname ASC, ca.c_firstname ASC, ca.c_middlename ASC";
  } elseif ($sort === 'name_desc') {
    $orderSql = "ORDER BY ca.c_lastname DESC, ca.c_firstname DESC, ca.c_middlename DESC";
  }

  $countSql = "
    SELECT COUNT(*)
    FROM tbl_child_archive ca
    LEFT JOIN tbl_barangay b ON b.barangay_id = ca.barangay_id
    $whereSql
  ";
  $st = $pdo->prepare($countSql);
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  $listSql = "
    SELECT
      ca.archived_id,
      ca.child_seq,
      ca.c_firstname,
      ca.c_middlename,
      ca.c_lastname,
      ca.date_birth,
      ca.g_firstname,
      ca.g_middlename,
      ca.g_lastname,
      ca.sex,
      ca.purok,
      b.barangay_name,
      NULL AS last_updated
    FROM tbl_child_archive ca
    LEFT JOIN tbl_barangay b ON b.barangay_id = ca.barangay_id
    $whereSql
    $orderSql
    LIMIT $limit OFFSET $offset
  ";
  $st = $pdo->prepare($listSql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "message" => "OK",
    "view" => $view,
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "rows" => $rows
  ]);
  exit;
}

// --------------------
// ACTIVE LIST
// --------------------
$where = [];
$params = [];

if ($role !== 'admin') {
  $where[] = "ci.barangay_id = ?";
  $params[] = $userBarangayId;
}

if ($q !== '') {
  $where[] = "(
    CONCAT_WS(' ', ci.c_firstname, ci.c_middlename, ci.c_lastname) LIKE ?
    OR CONCAT_WS(' ', ci.g_firstname, ci.g_middlename, ci.g_lastname) LIKE ?
    OR COALESCE(ci.purok, '') LIKE ?
    OR COALESCE(b.barangay_name, '') LIKE ?
  )";
  $like = "%{$q}%";
  array_push($params, $like, $like, $like, $like);
}

if ($sex !== '') {
  $where[] = "LOWER(COALESCE(ci.sex, '')) = LOWER(?)";
  $params[] = $sex;
}

if ($measurement === 'with') {
  $where[] = "lm.last_measured IS NOT NULL";
} elseif ($measurement === 'without') {
  $where[] = "lm.last_measured IS NULL";
}

if ($schedule === 'monthly') {
  $where[] = "TIMESTAMPDIFF(MONTH, ci.date_birth, CURDATE()) BETWEEN 0 AND 23";
} elseif ($schedule === 'quarterly') {
  $where[] = "TIMESTAMPDIFF(MONTH, ci.date_birth, CURDATE()) BETWEEN 24 AND 59";
}

if ($view === 'updated') {
  $where[] = "lm.last_measured >= ? AND lm.last_measured < ?";
  $params[] = $firstDay;
  $params[] = $nextMonthFirstDay;
} elseif ($view === 'outdated') {
  $where[] = "(lm.last_measured IS NULL OR lm.last_measured < ?)";
  $params[] = $firstDay;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$orderSql = "ORDER BY COALESCE(lm.last_measured, '1900-01-01') DESC, ci.child_seq DESC";
if ($sort === 'oldest') {
  $orderSql = "ORDER BY COALESCE(lm.last_measured, '1900-01-01') ASC, ci.child_seq ASC";
} elseif ($sort === 'name_asc') {
  $orderSql = "ORDER BY ci.c_lastname ASC, ci.c_firstname ASC, ci.c_middlename ASC";
} elseif ($sort === 'name_desc') {
  $orderSql = "ORDER BY ci.c_lastname DESC, ci.c_firstname DESC, ci.c_middlename DESC";
}

$countSql = "
  SELECT COUNT(*)
  FROM tbl_child_info ci
  LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_measured
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq
  $whereSql
";
$st = $pdo->prepare($countSql);
$st->execute($params);
$total = (int)$st->fetchColumn();

$listSql = "
  SELECT
    ci.child_seq,
    ci.c_firstname,
    ci.c_middlename,
    ci.c_lastname,
    ci.date_birth,
    ci.g_firstname,
    ci.g_middlename,
    ci.g_lastname,
    ci.sex,
    ci.purok,
    b.barangay_name,
    lm.last_measured AS last_updated
  FROM tbl_child_info ci
  LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_measured
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq
  $whereSql
  $orderSql
  LIMIT $limit OFFSET $offset
";

$st = $pdo->prepare($listSql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "message" => "OK",
  "view" => $view,
  "page" => $page,
  "limit" => $limit,
  "total" => $total,
  "measurement" => $measurement,
  "schedule" => $schedule,
  "rows" => $rows
]);