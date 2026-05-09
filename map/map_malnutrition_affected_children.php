<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

header("Content-Type: application/json; charset=utf-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function norm_status($v) {
  return strtolower(trim((string)$v));
}

try {
  authenticate(['admin', 'user', 'bns']);

  $barangayId = (int)($_GET['barangay_id'] ?? 0);
  $barangayCode = trim((string)($_GET['barangay_code'] ?? ''));
  $indicator = trim((string)($_GET['indicator'] ?? 'wfa'));
  $status = trim((string)($_GET['status'] ?? 'all_malnutrition'));
  $year = (int)($_GET['year'] ?? date('Y'));

  $allowedIndicators = ['wfa', 'hfa', 'wfh', 'muac'];
  if (!in_array($indicator, $allowedIndicators, true)) {
    $indicator = 'wfa';
  }

  if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
  }

  $where = [];
  $params = [];

  if ($barangayId > 0) {
    $where[] = "ci.barangay_id = ?";
    $params[] = $barangayId;
  } elseif ($barangayCode !== '') {
    $where[] = "b.barangay_code = ?";
    $params[] = $barangayCode;
  } else {
    out(400, [
      "ok" => false,
      "message" => "Missing barangay identifier"
    ]);
  }

  $where[] = "YEAR(m.date_measured) = ?";
  $params[] = $year;

  if ($indicator === 'wfa') {
    if ($status === 'underweight') {
      $where[] = "LOWER(m.weight_status) = 'underweight'";
    } elseif ($status === 'severely_underweight') {
      $where[] = "LOWER(m.weight_status) = 'severely underweight'";
    } else {
      $where[] = "LOWER(m.weight_status) IN ('underweight', 'severely underweight')";
    }
  }

  if ($indicator === 'hfa') {
    if ($status === 'stunted') {
      $where[] = "LOWER(m.height_status) = 'stunted'";
    } elseif ($status === 'severely_stunted') {
      $where[] = "LOWER(m.height_status) = 'severely stunted'";
    } else {
      $where[] = "LOWER(m.height_status) IN ('stunted', 'severely stunted')";
    }
  }

  if ($indicator === 'wfh') {
    if ($status === 'wasted') {
      $where[] = "LOWER(m.lt_status) = 'wasted'";
    } elseif ($status === 'severely_wasted') {
      $where[] = "LOWER(m.lt_status) = 'severely wasted'";
    } elseif ($status === 'overweight') {
      $where[] = "LOWER(m.lt_status) = 'overweight'";
    } elseif ($status === 'obese') {
      $where[] = "LOWER(m.lt_status) = 'obese'";
    } else {
      $where[] = "LOWER(m.lt_status) IN ('wasted', 'severely wasted', 'overweight', 'obese')";
    }
  }

  if ($indicator === 'muac') {
    if ($status === 'mam_yellow') {
      $where[] = "LOWER(m.muac_status) IN ('mam', 'yellow', 'mam / yellow')";
    } elseif ($status === 'sam_red') {
      $where[] = "LOWER(m.muac_status) IN ('sam', 'red', 'sam / red')";
    } else {
      $where[] = "LOWER(m.muac_status) IN ('mam', 'yellow', 'mam / yellow', 'sam', 'red', 'sam / red')";
    }
  }

  $whereSql = "WHERE " . implode(" AND ", $where);

  $sql = "
    SELECT
      ci.child_seq,
      CONCAT_WS(' ', ci.c_firstname, ci.c_middlename, ci.c_lastname) AS child_name,
      ci.sex,
      ci.date_birth,
      ci.purok,
      b.barangay_name,
      m.measure_id,
      m.date_measured,
      m.age_months,
      m.weight,
      m.height,
      m.muac,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.bilateral_pitting
    FROM tbl_measurement m
    INNER JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    $whereSql
    ORDER BY ci.c_lastname ASC, ci.c_firstname ASC, m.date_measured DESC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "message" => "OK",
    "year" => $year,
    "indicator" => $indicator,
    "status" => $status,
    "total" => count($rows),
    "rows" => $rows
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}