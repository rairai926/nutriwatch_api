<?php


require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . '/../middleware/auth.php';

try {
  // ✅ Fast + accurate aggregation per barangay using subqueries
  $sql = "
    SELECT
      b.barangay_id,
      b.barangay_name,

      COALESCE(ci_counts.child_count, 0) AS child_count,
      COALESCE(m_counts.measured_children, 0) AS measured_children,
      m_counts.last_measurement_date

    FROM tbl_barangay b

    LEFT JOIN (
      SELECT barangay_id, COUNT(*) AS child_count
      FROM tbl_child_info
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
      GROUP BY ci.barangay_id
    ) m_counts
      ON m_counts.barangay_id = b.barangay_id

    ORDER BY b.barangay_name ASC
  ";

  $stmt = $pdo->query($sql);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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