<?php


require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$user = authenticate(['admin', 'user']);
$role = $user->role ?? 'user';
$userId = (int)($user->sub ?? 0);

$metric = trim($_GET["metric"] ?? "lt_status");
$allowed = ["lt_status","weight_status","height_status","muac_status"];
if (!in_array($metric, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["message" => "Invalid metric"]);
  exit;
}

// get BNS barangay scope
$barangayId = 0;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);
  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["message" => "No barangay assigned"]);
    exit;
  }
}

$where = "";
$params = [];
if ($role !== 'admin') {
  $where = "WHERE ci.barangay_id = ?";
  $params[] = $barangayId;
}

$sql = "
  SELECT
    COALESCE(NULLIF(m.$metric,''),'Unknown') AS label,
    COUNT(*) AS total
  FROM tbl_measurement m
  JOIN (
    SELECT child_seq, MAX(date_measured) AS max_date
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = m.child_seq AND lm.max_date = m.date_measured
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  $where
  GROUP BY label
  ORDER BY total DESC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$labels = [];
$series = [];
$totalAll = 0;
foreach ($rows as $r) {
  $labels[] = $r["label"];
  $series[] = (int)$r["total"];
  $totalAll += (int)$r["total"];
}

echo json_encode([
  "metric" => $metric,
  "total" => $totalAll,
  "labels" => $labels,
  "series" => $series
]);