<?php
ob_start();
session_start();
header("Content-Type: application/json; charset=utf-8");

// CORS
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
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

// allow admin + bns
$user = authenticate(['admin', 'user']);
$role = $user->role ?? 'user';
$userId = (int)($user->sub ?? 0);

// Get BNS barangay_id from tbl_users
$barangayId = null;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $urow = $st->fetch(PDO::FETCH_ASSOC);
  $barangayId = isset($urow["barangay_id"]) ? (int)$urow["barangay_id"] : 0;

  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["message" => "No barangay assigned to this account"]);
    exit;
  }
}

// Build WHERE for child scope
$childWhere = "";
$params = [];
if ($role !== 'admin') {
  $childWhere = "WHERE ci.barangay_id = ?";
  $params[] = $barangayId;
}

// Quarter start (MySQL expression)
$quarterSql = "
  CASE
    WHEN QUARTER(CURDATE()) = 1 THEN MAKEDATE(YEAR(CURDATE()), 1)
    WHEN QUARTER(CURDATE()) = 2 THEN STR_TO_DATE(CONCAT(YEAR(CURDATE()), '-04-01'), '%Y-%m-%d')
    WHEN QUARTER(CURDATE()) = 3 THEN STR_TO_DATE(CONCAT(YEAR(CURDATE()), '-07-01'), '%Y-%m-%d')
    ELSE STR_TO_DATE(CONCAT(YEAR(CURDATE()), '-10-01'), '%Y-%m-%d')
  END
";

// Latest measurement per child
$latestJoin = "
  JOIN (
    SELECT child_seq, MAX(date_measured) AS max_date
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = m.child_seq AND lm.max_date = m.date_measured
";

// Total children
$sqlTotal = "SELECT COUNT(*) AS total_children FROM tbl_child_info ci $childWhere";
$st = $pdo->prepare($sqlTotal);
$st->execute($params);
$totalChildren = (int)($st->fetchColumn() ?: 0);

// Measured this quarter
$sqlMeasuredQ = "
  SELECT COUNT(DISTINCT m.child_seq) AS measured_this_quarter
  FROM tbl_measurement m
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  WHERE m.date_measured >= ($quarterSql)
  " . ($role !== 'admin' ? "AND ci.barangay_id = ?" : "");
$st = $pdo->prepare($sqlMeasuredQ);
$st->execute($role !== 'admin' ? [$barangayId] : []);
$measuredThisQuarter = (int)($st->fetchColumn() ?: 0);

// High risk children (latest measurement, lt_status not Normal or null)
$sqlHighRisk = "
  SELECT COUNT(*) AS high_risk
  FROM tbl_measurement m
  $latestJoin
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  WHERE (m.lt_status IS NULL OR m.lt_status <> 'Normal')
  " . ($role !== 'admin' ? "AND ci.barangay_id = ?" : "");
$st = $pdo->prepare($sqlHighRisk);
$st->execute($role !== 'admin' ? [$barangayId] : []);
$highRisk = (int)($st->fetchColumn() ?: 0);

$coverage = ($totalChildren > 0) ? round(($measuredThisQuarter / $totalChildren) * 100, 1) : 0.0;

// ------------------------------
// ALERT 1: Children without recent measurement (90 days)
// ------------------------------
$sqlNoRecent = "
  SELECT COUNT(*)
  FROM tbl_child_info ci
  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_date
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq
  WHERE (
    lm.last_date IS NULL
    OR lm.last_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
  )
  " . ($role !== 'admin' ? "AND ci.barangay_id = ?" : "");

$st = $pdo->prepare($sqlNoRecent);
$st->execute($role !== 'admin' ? [$barangayId] : []);
$noRecentMeasurement = (int)($st->fetchColumn() ?: 0);

// Recent measurements (latest 10 within scope)
$sqlRecent = "
  SELECT
    m.child_seq,
    ci.c_firstname, ci.c_middlename, ci.c_lastname,
    b.barangay_name,
    m.date_measured,
    m.weight_status, m.height_status, m.lt_status, m.muac_status
  FROM tbl_measurement m
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
  " . ($role !== 'admin' ? "WHERE ci.barangay_id = ?" : "") . "
  ORDER BY m.date_measured DESC
  LIMIT 10
";
$st = $pdo->prepare($sqlRecent);
$st->execute($role !== 'admin' ? [$barangayId] : []);
$recent = $st->fetchAll(PDO::FETCH_ASSOC);

// Admin-only: top barangays by coverage
$topBarangays = [];
if ($role === 'admin') {
  $sqlTop = "
    SELECT
      b.barangay_id,
      b.barangay_name,
      COUNT(DISTINCT ci.child_seq) AS child_count,
      COUNT(DISTINCT CASE WHEN m.date_measured >= ($quarterSql) THEN m.child_seq END) AS measured_this_quarter
    FROM tbl_barangay b
    LEFT JOIN tbl_child_info ci ON ci.barangay_id = b.barangay_id
    LEFT JOIN tbl_measurement m ON m.child_seq = ci.child_seq
    GROUP BY b.barangay_id, b.barangay_name
    ORDER BY
      (
        CASE
          WHEN COUNT(DISTINCT ci.child_seq) = 0 THEN 0
          ELSE (
            COUNT(DISTINCT CASE WHEN m.date_measured >= ($quarterSql) THEN m.child_seq END)
            / COUNT(DISTINCT ci.child_seq)
          )
        END
      ) DESC
    LIMIT 5
  ";
  $topBarangays = $pdo->query($sqlTop)->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------
// ALERT 2: Barangays below coverage threshold (Admin only)
// ------------------------------
$belowCoverageCount = 0;

if ($role === 'admin') {
  $sqlBelow = "
    SELECT COUNT(*) FROM (
      SELECT
        b.barangay_id,
        COUNT(DISTINCT ci.child_seq) AS child_count,
        COUNT(DISTINCT CASE WHEN m.date_measured >= ($quarterSql) THEN m.child_seq END) AS measured
      FROM tbl_barangay b
      LEFT JOIN tbl_child_info ci ON ci.barangay_id = b.barangay_id
      LEFT JOIN tbl_measurement m ON m.child_seq = ci.child_seq
      GROUP BY b.barangay_id
      HAVING (
        CASE
          WHEN COUNT(DISTINCT ci.child_seq) = 0 THEN 0
          ELSE (
            COUNT(DISTINCT CASE WHEN m.date_measured >= ($quarterSql) THEN m.child_seq END)
            / COUNT(DISTINCT ci.child_seq)
          ) * 100
        END
      ) < 80
    ) t
  ";

  $belowCoverageCount = (int)($pdo->query($sqlBelow)->fetchColumn() ?: 0);
}

echo json_encode([
  "scope" => ($role === 'admin') ? "all" : "barangay",
  "barangay_id" => $barangayId,
  "kpi" => [
    "total_children" => $totalChildren,
    "measured_this_quarter" => $measuredThisQuarter,
    "coverage_percent" => $coverage,
    "high_risk_children" => $highRisk
  ],
  "alerts" => [
    "no_recent_measurement" => $noRecentMeasurement,
    "below_coverage_barangays" => $belowCoverageCount
  ],
  "recent_measurements" => $recent,
  "top_barangays" => $topBarangays
]);