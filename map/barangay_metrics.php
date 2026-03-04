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

$authUser = authenticate(['admin', 'user']);
$role = $authUser->role ?? 'user';
$userId = (int)($authUser->sub ?? 0);

// --------------------
// Metric requested by frontend
// --------------------
$metric = trim($_GET["metric"] ?? "weight_status");
$allowed = ["weight_status", "height_status", "lt_status", "muac_status"];
if (!in_array($metric, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["message" => "Invalid metric"]);
  exit;
}

// BNS scope (optional)
// If you want BNS to only see their barangay on map set to true
$restrictToBarangay = false;

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

// --------------------
// MUAC mapping + what counts as "Normal"
// --------------------
$labelExpr  = "COALESCE(NULLIF(m.$metric,''),'Unknown')";
$normalExpr = "LOWER(m.$metric) = 'normal'";

if ($metric === "muac_status") {
  $labelExpr = "
    CASE
      WHEN m.muac_status IS NULL OR m.muac_status = '' THEN 'Unknown'
      WHEN LOWER(m.muac_status) LIKE '%green%' THEN 'Normal (Well-nourished)'
      WHEN LOWER(m.muac_status) LIKE '%yellow%' THEN 'Moderate Acute Malnutrition (MAM)'
      WHEN LOWER(m.muac_status) LIKE '%red%' THEN 'Severe Acute Malnutrition (SAM)'
      ELSE m.muac_status
    END
  ";
  $normalExpr = "(LOWER(m.muac_status) LIKE '%green%' OR LOWER(m.muac_status) LIKE '%normal%')";
}

// --------------------
// Latest measurement per child:
// 1) max date_measured per child
// 2) if multiple on same date, use max measure_id
// --------------------
$latestJoin = "
  JOIN (
    SELECT child_seq, MAX(date_measured) AS max_date
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm
    ON lm.child_seq = m.child_seq AND lm.max_date = m.date_measured
";

$latestTieBreaker = "
  JOIN (
    SELECT child_seq, date_measured, MAX(measure_id) AS max_measure_id
    FROM tbl_measurement
    GROUP BY child_seq, date_measured
  ) lt
    ON lt.child_seq = m.child_seq
   AND lt.date_measured = m.date_measured
   AND lt.max_measure_id = m.measure_id
";

// --------------------
// 1) Base barangay list + child totals + assigned BNS
// barangay_code matches GeoJSON properties.Bgy_Code
// --------------------
$whereBarangay = "";
$params = [];

if ($restrictToBarangay && $role !== 'admin') {
  $whereBarangay = "WHERE b.barangay_id = ?";
  $params[] = $barangayId;
}

$barangaySql = "
  SELECT
    b.barangay_id,
    b.barangay_code,
    b.barangay_name,

    MAX(
      CASE
        WHEN u.role = 'user' AND u.status = 'active'
        THEN CONCAT(u.lastname, ', ', u.firstname, IFNULL(CONCAT(' ', u.middlename), ''))
        ELSE NULL
      END
    ) AS assigned_bns,

    COUNT(DISTINCT ci.child_seq) AS total_children,
    COUNT(DISTINCT CASE WHEN LOWER(ci.sex) IN ('m','male','boy','boys') THEN ci.child_seq END) AS male_children,
    COUNT(DISTINCT CASE WHEN LOWER(ci.sex) IN ('f','female','girl','girls') THEN ci.child_seq END) AS female_children

  FROM tbl_barangay b
  LEFT JOIN tbl_child_info ci
    ON ci.barangay_id = b.barangay_id

  LEFT JOIN tbl_users u
    ON u.barangay_id = b.barangay_id

  $whereBarangay
  GROUP BY b.barangay_id, b.barangay_code, b.barangay_name
  ORDER BY b.barangay_name ASC
";

$st = $pdo->prepare($barangaySql);
$st->execute($params);
$barangays = $st->fetchAll(PDO::FETCH_ASSOC);

// Build output keyed by barangay_id
$byId = [];
foreach ($barangays as $b) {
  $id = (int)$b["barangay_id"];
  $byId[$id] = [
    "barangay_id" => $id,
    "barangay_code" => trim((string)($b["barangay_code"] ?? "")),
    "barangay_name" => $b["barangay_name"] ?? "",

    "assigned_bns" => $b["assigned_bns"] ?? "",

    "total_children" => (int)($b["total_children"] ?? 0),
    "male_children" => (int)($b["male_children"] ?? 0),
    "female_children" => (int)($b["female_children"] ?? 0),

    "measured_children" => 0,
    "last_measurement_date" => null,
    "normal_pct" => 0,

    "breakdowns" => [
      $metric => []
    ]
  ];
}

if (!$byId) {
  echo json_encode([]);
  exit;
}

// --------------------
// 2) Metric breakdown (latest per child) per barangay
// --------------------
$ids = array_keys($byId);
$placeholders = implode(",", array_fill(0, count($ids), "?"));

$metricSql = "
  SELECT
    ci.barangay_id,
    MAX(m.date_measured) AS last_measurement_date,
    $labelExpr AS label,
    COUNT(*) AS label_total
  FROM tbl_measurement m
  $latestJoin
  $latestTieBreaker
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  WHERE ci.barangay_id IN ($placeholders)
  GROUP BY ci.barangay_id, label
";

$st = $pdo->prepare($metricSql);
$st->execute($ids);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$tmpAgg = []; // barangay_id => { measured, last, breakdown[] }
foreach ($rows as $r) {
  $bid = (int)$r["barangay_id"];
  if (!isset($tmpAgg[$bid])) {
    $tmpAgg[$bid] = ["measured" => 0, "last" => null, "breakdown" => []];
  }

  $tmpAgg[$bid]["measured"] += (int)$r["label_total"];

  $d = $r["last_measurement_date"];
  if ($d && (!$tmpAgg[$bid]["last"] || $d > $tmpAgg[$bid]["last"])) {
    $tmpAgg[$bid]["last"] = $d;
  }

  $tmpAgg[$bid]["breakdown"][] = [
    "label" => $r["label"],
    "total" => (int)$r["label_total"]
  ];
}

// --------------------
// 3) Accurate normal_count per barangay (latest per child)
// --------------------
$normalSql = "
  SELECT
    ci.barangay_id,
    SUM(CASE WHEN $normalExpr THEN 1 ELSE 0 END) AS normal_count
  FROM tbl_measurement m
  $latestJoin
  $latestTieBreaker
  JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
  WHERE ci.barangay_id IN ($placeholders)
  GROUP BY ci.barangay_id
";

$st = $pdo->prepare($normalSql);
$st->execute($ids);
$normalRows = $st->fetchAll(PDO::FETCH_ASSOC);

$normalMap = [];
foreach ($normalRows as $nr) {
  $normalMap[(int)$nr["barangay_id"]] = (int)$nr["normal_count"];
}

// --------------------
// 4) Merge into output
// --------------------
foreach ($byId as $bid => &$out) {
  if (isset($tmpAgg[$bid])) {
    $measured = (int)$tmpAgg[$bid]["measured"];
    $normal   = (int)($normalMap[$bid] ?? 0);

    $out["measured_children"] = $measured;
    $out["last_measurement_date"] = $tmpAgg[$bid]["last"];
    $out["normal_pct"] = $measured > 0 ? round(($normal / $measured) * 100, 1) : 0;

    // sort breakdown: highest count first
    usort($tmpAgg[$bid]["breakdown"], fn($a, $b) => $b["total"] <=> $a["total"]);
    $out["breakdowns"][$metric] = $tmpAgg[$bid]["breakdown"];
  }
}

echo json_encode(array_values($byId));