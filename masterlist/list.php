<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS
// --------------------
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://nutriwatch.com",
  "http://192.168.1.36:3000"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Vary: Origin");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

// --------------------
// Params (MATCH your React)
// view: all | updated | outdated | archive
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
// ARCHIVE VIEW (tbl_child_archive) — MATCH fields your table uses
// --------------------
if ($view === 'archive') {
  $where = [];
  $params = [];

  if ($role !== 'admin') {
    $where[] = "ca.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

  // total
  $countSql = "SELECT COUNT(*) FROM tbl_child_archive ca $whereSql";
  $st = $pdo->prepare($countSql);
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  // rows (return c_* fields + purok + barangay_name + last_updated)
  $listSql = "
    SELECT
      ca.archived_id,
      ca.c_firstname,
      ca.c_middlename,
      ca.c_lastname,
      ca.sex,
      ca.purok,
      b.barangay_name,
      NULL AS last_updated
    FROM tbl_child_archive ca
    LEFT JOIN tbl_barangay b ON b.barangay_id = ca.barangay_id
    $whereSql
    ORDER BY ca.archived_id DESC
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
// ACTIVE LIST (tbl_child_info) + latest measurement date as last_updated
// For updated/outdated: filter using latest measurement date per child
// --------------------
$where = [];
$params = [];

if ($role !== 'admin') {
  $where[] = "ci.barangay_id = ?";
  $params[] = $userBarangayId;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// view filter based on latest measurement date
$viewFilterSql = "";
if ($view === 'updated') {
  // this month
  $viewFilterSql = " AND lm.last_measured >= ? AND lm.last_measured < ? ";
  $params[] = $firstDay;
  $params[] = $nextMonthFirstDay;
} elseif ($view === 'outdated') {
  // no measurement or older than this month
  $viewFilterSql = " AND (lm.last_measured IS NULL OR lm.last_measured < ?) ";
  $params[] = $firstDay;
}

// total
$countSql = "
  SELECT COUNT(*)
  FROM tbl_child_info ci
  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_measured
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq
  $whereSql
  $viewFilterSql
";
$st = $pdo->prepare($countSql);
$st->execute($params);
$total = (int)$st->fetchColumn();

// rows
$listSql = "
  SELECT
    ci.child_seq,
    ci.c_firstname,
    ci.c_middlename,
    ci.c_lastname,
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
  $viewFilterSql
  ORDER BY COALESCE(lm.last_measured, '1900-01-01') DESC, ci.child_seq DESC
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