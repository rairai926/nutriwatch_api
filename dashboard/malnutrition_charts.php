<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

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

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
  http_response_code(405);
  echo json_encode(["message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function pct($count, $total) {
  if ((int)$total <= 0) return 0;
  return round(((float)$count / (float)$total) * 100, 2);
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $month = (int)($_GET['month'] ?? date('n'));
  $year = (int)($_GET['year'] ?? date('Y'));
  $requestedBarangayId = (int)($_GET['barangay_id'] ?? 0);

  if ($month < 1 || $month > 12) {
    out(422, ["message" => "Invalid month"]);
  }

  if ($year < 2000 || $year > 2100) {
    out(422, ["message" => "Invalid year"]);
  }

  $barangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $st->execute([$userId]);
    $barangayId = (int)($st->fetchColumn() ?: 0);

    if ($barangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  } else {
    $barangayId = $requestedBarangayId > 0 ? $requestedBarangayId : 0;
  }

  $whereBarangay = "";
  $params = [$month, $year, $month, $year];

  if ($barangayId > 0) {
    $whereBarangay = " AND ci.barangay_id = ? ";
    $params[] = $barangayId;
  }

  $sql = "
    SELECT
      x.child_seq,
      x.date_measured,
      x.age_months,
      LOWER(TRIM(COALESCE(x.weight_status, ''))) AS weight_status,
      LOWER(TRIM(COALESCE(x.height_status, ''))) AS height_status,
      LOWER(TRIM(COALESCE(x.lt_status, ''))) AS lt_status,
      LOWER(TRIM(COALESCE(x.muac_status, ''))) AS muac_status
    FROM tbl_measurement x
    INNER JOIN (
      SELECT child_seq, MAX(date_measured) AS max_date
      FROM tbl_measurement
      WHERE MONTH(date_measured) = ? AND YEAR(date_measured) = ?
      GROUP BY child_seq
    ) lm
      ON lm.child_seq = x.child_seq
     AND lm.max_date = x.date_measured
    INNER JOIN (
      SELECT child_seq, date_measured, MAX(measure_id) AS max_measure_id
      FROM tbl_measurement
      WHERE MONTH(date_measured) = ? AND YEAR(date_measured) = ?
      GROUP BY child_seq, date_measured
    ) lt
      ON lt.child_seq = x.child_seq
     AND lt.date_measured = x.date_measured
     AND lt.max_measure_id = x.measure_id
    INNER JOIN tbl_child_info ci
      ON ci.child_seq = x.child_seq
    WHERE 1 = 1
      $whereBarangay
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $total0to59 = 0;
  $total6to59 = 0;

  $mw = 0;
  $sw = 0;
  $wasted = 0;

  $mst = 0;
  $sst = 0;
  $stunted = 0;

  $overweight = 0;
  $obese = 0;
  $owOrOb = 0;

  $muw = 0;
  $suw = 0;
  $underweight = 0;

  $muw_mst = 0;
  $muw_sst = 0;
  $suw_mst = 0;
  $suw_sst = 0;
  $underweightOrStunted = 0;

  $mst_mw = 0;
  $mst_sw = 0;
  $sst_mw = 0;
  $sst_sw = 0;
  $stuntedAndWasted = 0;

  $mst_ob = 0;
  $mst_ow = 0;
  $sst_ob = 0;
  $sst_ow = 0;
  $stuntedAndOvernutrition = 0;

  $muacNormal = 0;
  $muacMW = 0;
  $muacSW = 0;

  foreach ($rows as $r) {
    $ageMonths = isset($r['age_months']) ? (int)$r['age_months'] : -1;
    if ($ageMonths < 0) continue;

    $weightStatus = $r['weight_status'];
    $heightStatus = $r['height_status'];
    $ltStatus = $r['lt_status'];
    $muacStatus = $r['muac_status'];

    $is0to59 = ($ageMonths >= 0 && $ageMonths <= 59);
    $is6to59 = ($ageMonths >= 6 && $ageMonths <= 59);

    if ($is0to59) {
      $total0to59++;

      $isMW = ($ltStatus === 'wasted');
      $isSW = ($ltStatus === 'severely wasted');
      $isWasted = ($isMW || $isSW);

      $isMSt = ($heightStatus === 'stunted');
      $isSSt = ($heightStatus === 'severely stunted');
      $isStunted = ($isMSt || $isSSt);

      $isMUW = ($weightStatus === 'underweight');
      $isSUW = ($weightStatus === 'severely underweight');
      $isUnderweight = ($isMUW || $isSUW);

      $isOW = ($ltStatus === 'overweight');
      $isOb = ($ltStatus === 'obese');
      $isOvernutrition = ($isOW || $isOb);

      if ($isMW) $mw++;
      if ($isSW) $sw++;
      if ($isWasted) $wasted++;

      if ($isMSt) $mst++;
      if ($isSSt) $sst++;
      if ($isStunted) $stunted++;

      if ($isOW) $overweight++;
      if ($isOb) $obese++;
      if ($isOvernutrition) $owOrOb++;

      if ($isMUW) $muw++;
      if ($isSUW) $suw++;
      if ($isUnderweight) $underweight++;

      if ($isMUW && $isMSt) $muw_mst++;
      if ($isMUW && $isSSt) $muw_sst++;
      if ($isSUW && $isMSt) $suw_mst++;
      if ($isSUW && $isSSt) $suw_sst++;
      if ($isUnderweight && $isStunted) $underweightOrStunted++;

      if ($isMSt && $isMW) $mst_mw++;
      if ($isMSt && $isSW) $mst_sw++;
      if ($isSSt && $isMW) $sst_mw++;
      if ($isSSt && $isSW) $sst_sw++;
      if ($isStunted && $isWasted) $stuntedAndWasted++;

      if ($isMSt && $isOb) $mst_ob++;
      if ($isMSt && $isOW) $mst_ow++;
      if ($isSSt && $isOb) $sst_ob++;
      if ($isSSt && $isOW) $sst_ow++;
      if ($isStunted && $isOvernutrition) $stuntedAndOvernutrition++;
    }

    if ($is6to59) {
      $total6to59++;

      if ($muacStatus === 'normal') $muacNormal++;
      if ($muacStatus === 'mam') $muacMW++;
      if ($muacStatus === 'sam') $muacSW++;
    }
  }

  $barangays = [];
  if ($role === 'admin') {
    $st = $pdo->query("
      SELECT barangay_id, barangay_name
      FROM tbl_barangay
      ORDER BY barangay_name ASC
    ");
    $barangays = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  out(200, [
    "month" => $month,
    "year" => $year,
    "barangay_id" => $barangayId,
    "base_0_59" => $total0to59,
    "base_6_59" => $total6to59,
    "barangays" => $barangays,

    "wasted" => [
      pct($mw, $total0to59),
      pct($sw, $total0to59),
      pct($wasted, $total0to59)
    ],

    "stunted" => [
      pct($mst, $total0to59),
      pct($sst, $total0to59),
      pct($stunted, $total0to59)
    ],

    "obeseOverweight" => [
      pct($overweight, $total0to59),
      pct($obese, $total0to59),
      pct($owOrOb, $total0to59)
    ],

    "underweightOrStunted" => [
      pct($muw_mst, $total0to59),
      pct($muw_sst, $total0to59),
      pct($suw_mst, $total0to59),
      pct($suw_sst, $total0to59),
      pct($underweightOrStunted, $total0to59)
    ],

    "stuntedAndWasted" => [
      pct($mst_mw, $total0to59),
      pct($mst_sw, $total0to59),
      pct($sst_mw, $total0to59),
      pct($sst_sw, $total0to59),
      pct($stuntedAndWasted, $total0to59)
    ],

    "stuntedAndObeseOverweight" => [
      pct($mst_ob, $total0to59),
      pct($mst_ow, $total0to59),
      pct($sst_ob, $total0to59),
      pct($sst_ow, $total0to59),
      pct($stuntedAndOvernutrition, $total0to59)
    ],

    "underweight" => [
      pct($muw, $total0to59),
      pct($suw, $total0to59),
      pct($underweight, $total0to59)
    ],

    "muac" => [
      pct($muacNormal, $total6to59),
      pct($muacMW, $total6to59),
      pct($muacSW, $total6to59)
    ]
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}