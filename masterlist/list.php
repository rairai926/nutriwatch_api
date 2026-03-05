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

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $view = strtolower(trim($_GET['view'] ?? 'all'));
  $allowedViews = ['all', 'updated', 'outdated', 'archive'];
  if (!in_array($view, $allowedViews, true)) $view = 'all';

  // If not admin => restrict by their barangay_id
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

  // Latest measurement per child (date + tie-breaker)
  $latestJoin = "
    LEFT JOIN (
      SELECT m1.child_seq, m1.date_measured
      FROM tbl_measurement m1
      JOIN (
        SELECT child_seq, MAX(date_measured) AS max_date
        FROM tbl_measurement
        GROUP BY child_seq
      ) md
        ON md.child_seq = m1.child_seq AND md.max_date = m1.date_measured
      JOIN (
        SELECT child_seq, date_measured, MAX(measure_id) AS max_measure_id
        FROM tbl_measurement
        GROUP BY child_seq, date_measured
      ) mi
        ON mi.child_seq = m1.child_seq
       AND mi.date_measured = m1.date_measured
       AND mi.max_measure_id = m1.measure_id
    ) lm ON lm.child_seq = ci.child_seq
  ";

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

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    // Note: tbl_child_archive columns based on your ERD
    $sql = "
      SELECT
        ca.child_seq,
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
      ORDER BY ca.c_lastname ASC, ca.c_firstname ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["ok" => true, "rows" => $rows]);
    exit;
  }

  // --------------------
  // ACTIVE CHILDREN (tbl_child_info)
  // all / updated / outdated
  // --------------------
  $where = [];
  $params = [];

  if ($role !== 'admin') {
    $where[] = "ci.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  // Updated this month: last_updated in current month/year
  if ($view === 'updated') {
    $where[] = "lm.date_measured IS NOT NULL";
    $where[] = "YEAR(lm.date_measured) = YEAR(CURDATE())";
    $where[] = "MONTH(lm.date_measured) = MONTH(CURDATE())";
  }

  // Outdated this month: not measured this month (including never measured)
  if ($view === 'outdated') {
    $where[] = "(
      lm.date_measured IS NULL
      OR YEAR(lm.date_measured) <> YEAR(CURDATE())
      OR MONTH(lm.date_measured) <> MONTH(CURDATE())
    )";
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

  $sql = "
    SELECT
      ci.child_seq,
      ci.c_firstname,
      ci.c_middlename,
      ci.c_lastname,
      ci.sex,
      ci.purok,
      b.barangay_name,
      lm.date_measured AS last_updated
    FROM tbl_child_info ci
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    $latestJoin
    $whereSql
    ORDER BY ci.c_lastname ASC, ci.c_firstname ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(["ok" => true, "rows" => $rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}