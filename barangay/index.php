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

try {
  $provinceId = isset($_GET["province_id"]) ? (int)$_GET["province_id"] : 0;
  $cityId     = isset($_GET["city_id"]) ? (int)$_GET["city_id"] : 0;

  // Build filter conditions for child_info (used in both aggregates)
  $childWhere = [];
  $params = [];

  if ($provinceId > 0) {
    $childWhere[] = "province_id = ?";
    $params[] = $provinceId;
  }
  if ($cityId > 0) {
    $childWhere[] = "city_id = ?";
    $params[] = $cityId;
  }

  $childFilterSql = "";
  if (!empty($childWhere)) {
    $childFilterSql = "WHERE " . implode(" AND ", $childWhere);
  }

  // ✅ FIXED: Use aggregated subqueries (prevents wrong counts)
  // - children per barangay
  // - measured unique children per barangay + max date_measured
  $sql = "
    SELECT
      b.barangay_id,
      b.barangay_name,

      COALESCE(ci_counts.child_count, 0) AS child_count,
      COALESCE(m_counts.measured_children, 0) AS measured_children,
      m_counts.last_measurement_date

    FROM tbl_barangay b

    LEFT JOIN (
      SELECT
        barangay_id,
        COUNT(*) AS child_count
      FROM tbl_child_info
      $childFilterSql
      GROUP BY barangay_id
    ) ci_counts
      ON ci_counts.barangay_id = b.barangay_id

    LEFT JOIN (
      SELECT
        ci.barangay_id,
        COUNT(DISTINCT m.child_seq) AS measured_children,
        MAX(m.date_measured) AS last_measurement_date
      FROM tbl_measurement m
      JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
      " . (!empty($childWhere) ? "WHERE " . implode(" AND ", array_map(fn($w) => "ci." . $w, $childWhere)) : "") . "
      GROUP BY ci.barangay_id
    ) m_counts
      ON m_counts.barangay_id = b.barangay_id

    ORDER BY b.barangay_name ASC
  ";

  // NOTE: params only apply to ci_counts and m_counts when filters exist.
  // For m_counts, we reused the same conditions but prefixed with ci.
  // Because we used dynamic WHERE generation, we can reuse $params as-is.
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r["barangay_id"] = (int)$r["barangay_id"];
    $r["child_count"] = (int)$r["child_count"];
    $r["measured_children"] = (int)$r["measured_children"];
    // last_measurement_date remains string or null
  }

  echo json_encode($rows);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}