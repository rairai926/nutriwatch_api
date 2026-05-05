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

$month = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid month format. Use YYYY-MM"]);
  exit;
}

$startDate = $month . "-01";
$endDate = date("Y-m-d", strtotime($startDate . " +1 month"));

$restrictToBarangay = true;
$whereBarangay = "";
$params = [$startDate, $endDate];

if ($restrictToBarangay && $role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "No barangay assigned"]);
    exit;
  }

  $whereBarangay = "WHERE b.barangay_id = ?";
  $params[] = $barangayId;
}

$sql = "
  SELECT
    b.barangay_id,
    b.barangay_code,
    b.barangay_name,

    MAX(
      CASE
        WHEN LOWER(u.role) IN ('user','bns')
        THEN CONCAT(u.lastname, ', ', u.firstname, IFNULL(CONCAT(' ', u.middlename), ''))
        ELSE NULL
      END
    ) AS assigned_bns,

    COUNT(DISTINCT ci.child_seq) AS total_children,

    COUNT(DISTINCT CASE 
      WHEN m.date_measured >= ? AND m.date_measured < ?
      THEN ci.child_seq 
    END) AS measured_children,

    MAX(CASE 
      WHEN m.date_measured >= ? AND m.date_measured < ?
      THEN m.date_measured 
    END) AS last_measurement_date

  FROM tbl_barangay b

  LEFT JOIN tbl_child_info ci 
    ON ci.barangay_id = b.barangay_id

  LEFT JOIN tbl_measurement m 
    ON m.child_seq = ci.child_seq

  LEFT JOIN tbl_users u 
    ON u.barangay_id = b.barangay_id

  $whereBarangay

  GROUP BY b.barangay_id, b.barangay_code, b.barangay_name
  ORDER BY b.barangay_name ASC
";

$params = [$startDate, $endDate, $startDate, $endDate];

if ($restrictToBarangay && $role !== 'admin') {
  $params[] = $barangayId;
}

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$out = [];

foreach ($rows as $r) {
  $total = (int)($r['total_children'] ?? 0);
  $measured = (int)($r['measured_children'] ?? 0);
  $unmeasured = max(0, $total - $measured);

  $coverage = $total > 0 ? round(($measured / $total) * 100, 1) : 0;

  if ($total <= 0) {
    $priority = "no_data";
  } elseif ($coverage >= 100) {
    $priority = "good";
  } elseif ($coverage >= 50) {
    $priority = "low";
  } else {
    $priority = "high";
  }

  $out[] = [
    "barangay_id" => (int)$r['barangay_id'],
    "barangay_code" => strtoupper(preg_replace('/\s+/u', '', $r['barangay_code'] ?? '')),
    "barangay_name" => $r['barangay_name'],
    "assigned_bns" => $r['assigned_bns'] ?? "",
    "month" => $month,
    "total_children" => $total,
    "measured_children" => $measured,
    "unmeasured_children" => $unmeasured,
    "coverage_pct" => $coverage,
    "priority_level" => $priority,
    "last_measurement_date" => $r['last_measurement_date']
  ];
}

echo json_encode([
  "ok" => true,
  "month" => $month,
  "data" => $out
]);  