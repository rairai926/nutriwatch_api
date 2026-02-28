<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

// allow logged-in users (adjust roles if needed)
//$user = authenticate(['admin', 'user']);

header("Content-Type: application/json; charset=utf-8");

$barangayId = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
$metric = trim($_GET['metric'] ?? 'lt_status');

// whitelist columns to prevent SQL injection
$allowedMetrics = ['lt_status', 'weight_status', 'height_status', 'muac_status'];
if (!in_array($metric, $allowedMetrics, true)) {
  http_response_code(400);
  echo json_encode(["message" => "Invalid metric"]);
  exit;
}

if ($barangayId <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "barangay_id is required"]);
  exit;
}

// get barangay name
$stmt = $pdo->prepare("SELECT barangay_name FROM tbl_barangay WHERE barangay_id = ? LIMIT 1");
$stmt->execute([$barangayId]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$b) {
  http_response_code(404);
  echo json_encode(["message" => "Barangay not found"]);
  exit;
}

// latest measurement per child_seq, then group by selected metric
$sql = "
SELECT m.$metric AS status_label, COUNT(*) AS total
FROM tbl_measurement m
JOIN (
  SELECT child_seq, MAX(date_measured) AS max_date
  FROM tbl_measurement
  GROUP BY child_seq
) lm ON lm.child_seq = m.child_seq AND lm.max_date = m.date_measured
JOIN tbl_child_info c ON c.child_seq = m.child_seq
WHERE c.barangay_id = ?
GROUP BY m.$metric
ORDER BY total DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$barangayId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// format for chart
$labels = [];
$series = [];
$totalAll = 0;

foreach ($rows as $r) {
  $label = $r['status_label'] ?? 'Unknown';
  $count = (int)$r['total'];
  $labels[] = $label === '' ? 'Unknown' : $label;
  $series[] = $count;
  $totalAll += $count;
}

echo json_encode([
  "barangay_id" => $barangayId,
  "barangay_name" => $b["barangay_name"],
  "metric" => $metric,
  "total" => $totalAll,
  "labels" => $labels,
  "series" => $series
]);