<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS (must NOT be * if you use withCredentials anywhere)
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

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../config/db.php";

// OPTIONAL: if you have auth middleware
// require_once __DIR__ . "/../middleware/auth.php";
// $user = authenticate(['admin', 'user']); // or ['admin']

try {
  $provinceId = isset($_GET["province_id"]) ? (int)$_GET["province_id"] : 0;
  $cityId     = isset($_GET["city_id"]) ? (int)$_GET["city_id"] : 0;

  $where = [];
  $params = [];

  // We filter based on child_info location (because tbl_barangay has only name/id)
  // But we still want barangays with 0 children to appear IF no filter is applied.
  // When filters are applied, only barangays that have matching children will appear.

  if ($provinceId > 0) {
    $where[] = "ci.province_id = ?";
    $params[] = $provinceId;
  }
  if ($cityId > 0) {
    $where[] = "ci.city_id = ?";
    $params[] = $cityId;
  }

  // Aggregate measurements per barangay (unique children measured + latest measurement date)
  // Note: measured_children counts DISTINCT child_seq in measurement (so not double counted)
  $sql = "
    SELECT
      b.barangay_id,
      b.barangay_name,

      COUNT(DISTINCT ci.child_seq) AS child_count,

      COUNT(DISTINCT m.child_seq) AS measured_children,

      MAX(m.date_measured) AS last_measurement_date

    FROM tbl_barangay b
    LEFT JOIN tbl_child_info ci
      ON ci.barangay_id = b.barangay_id

    LEFT JOIN tbl_measurement m
      ON m.child_seq = ci.child_seq
  ";

  // If filter is applied, it will naturally limit to barangays with children matching filter
  if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
  }

  $sql .= "
    GROUP BY b.barangay_id, b.barangay_name
    ORDER BY b.barangay_name ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Normalize output
  foreach ($rows as &$r) {
    $r["barangay_id"] = (int)$r["barangay_id"];
    $r["child_count"] = (int)$r["child_count"];
    $r["measured_children"] = (int)$r["measured_children"];
    // last_measurement_date stays string or null
  }

  echo json_encode($rows);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}