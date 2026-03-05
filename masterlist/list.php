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
// Pagination + filters
// --------------------
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

$offset = ($page - 1) * $limit;

$view = trim($_GET['view'] ?? 'overall');
$allowedViews = ['overall', 'updated_this_month', 'outdated_this_month', 'archives'];
if (!in_array($view, $allowedViews, true)) $view = 'overall';

// --------------------
// Scope (user/bns only their barangay)
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
// Date boundaries (this month)
// --------------------
$firstDay = date('Y-m-01');
$nextMonthFirstDay = date('Y-m-01', strtotime('+1 month', strtotime($firstDay)));

// --------------------
// Helpers for WHERE clause
// --------------------
$where = [];
$params = [];

// barangay restriction
if ($role !== 'admin') {
  $where[] = "ci.barangay_id = ?";
  $params[] = $userBarangayId;
}

// view logic (based on latest measurement date per child)
$viewWhere = "";
if ($view === 'updated_this_month') {
  // last_measured in [firstDay, nextMonthFirstDay)
  $viewWhere = " AND lm.last_measured >= ? AND lm.last_measured < ? ";
  $params[] = $firstDay;
  $params[] = $nextMonthFirstDay;
} elseif ($view === 'outdated_this_month') {
  // last_measured is NULL OR < firstDay
  $viewWhere = " AND (lm.last_measured IS NULL OR lm.last_measured < ?) ";
  $params[] = $firstDay;
}

// --------------------
// ARCHIVES view (tbl_child_archive)
// --------------------
if ($view === 'archives') {
  $whereA = [];
  $paramsA = [];

  if ($role !== 'admin') {
    $whereA[] = "ca.barangay_id = ?";
    $paramsA[] = $userBarangayId;
  }

  $whereSqlA = $whereA ? ("WHERE " . implode(" AND ", $whereA)) : "";

  // count
  $countSql = "
    SELECT COUNT(*) 
    FROM tbl_child_archive ca
    $whereSqlA
  ";
  $st = $pdo->prepare($countSql);
  $st->execute($paramsA);
  $total = (int)$st->fetchColumn();

  // list
  $listSql = "
    SELECT
      ca.archived_id AS id,
      CONCAT_WS(' ', ca.c_firstname, ca.c_middlename, ca.c_lastname) AS full_name,
      CONCAT_WS(', ', NULLIF(ca.purok,''), b.barangay_name) AS address,
      ca.sex,
      ca.date_birth,
      NULL AS last_updated
    FROM tbl_child_archive ca
    LEFT JOIN tbl_barangay b ON b.barangay_id = ca.barangay_id
    $whereSqlA
    ORDER BY ca.archived_id DESC
    LIMIT $limit OFFSET $offset
  ";
  $st = $pdo->prepare($listSql);
  $st->execute($paramsA);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "view" => $view,
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "rows" => $rows
  ]);
  exit;
}

// --------------------
// ACTIVE children views (tbl_child_info) + last measurement
// --------------------
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// count
$countSql = "
  SELECT COUNT(*)
  FROM tbl_child_info ci
  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_measured
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq
  $whereSql
  $viewWhere
";
$st = $pdo->prepare($countSql);
$st->execute($params);
$total = (int)$st->fetchColumn();

// list
$listSql = "
  SELECT
    ci.child_seq AS id,
    CONCAT_WS(' ', ci.c_firstname, ci.c_middlename, ci.c_lastname) AS full_name,
    CONCAT_WS(', ', NULLIF(ci.purok,''), b.barangay_name) AS address,
    ci.sex,
    lm.last_measured AS last_updated
  FROM tbl_child_info ci
  LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_measured
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq
  $whereSql
  $viewWhere
  ORDER BY COALESCE(lm.last_measured, '1900-01-01') DESC, ci.child_seq DESC
  LIMIT $limit OFFSET $offset
";

$st = $pdo->prepare($listSql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "view" => $view,
  "page" => $page,
  "limit" => $limit,
  "total" => $total,
  "rows" => $rows
]);