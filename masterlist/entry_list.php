<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function months_diff(string $fromDate, string $toDate): int {
  try {
    $from = new DateTime($fromDate);
    $to = new DateTime($toDate);

    $years = (int)$to->format('Y') - (int)$from->format('Y');
    $months = (int)$to->format('n') - (int)$from->format('n');
    $diff = ($years * 12) + $months;

    if ((int)$to->format('j') < (int)$from->format('j')) {
      $diff -= 1;
    }

    return max(0, $diff);
  } catch (Throwable $e) {
    return 0;
  }
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  // --------------------
  // Params
  // --------------------
  $page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
  if ($page < 1) $page = 1;
  if ($limit < 1) $limit = 1;
  if ($limit > 100) $limit = 100;
  $offset = ($page - 1) * $limit;

  $q = trim((string)($_GET['q'] ?? ''));
  $filter = trim((string)($_GET['filter'] ?? 'all')); // all|eligible|023|2459|measured
  $allowedFilters = ['all', 'eligible', '023', '2459', 'measured'];
  if (!in_array($filter, $allowedFilters, true)) $filter = 'all';

  // --------------------
  // Restrict non-admin to their barangay
  // --------------------
  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }
  }

  // --------------------
  // Base WHERE
  // --------------------
  $where = [];
  $params = [];

  if ($role !== 'admin') {
    $where[] = "ci.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  if ($q !== '') {
    $where[] = "(
      CONCAT_WS(' ', ci.c_firstname, ci.c_middlename, ci.c_lastname) LIKE ?
      OR COALESCE(ci.date_birth, '') LIKE ?
    )";
    $like = "%{$q}%";
    array_push($params, $like, $like);
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

  // --------------------
  // Count
  // --------------------
  $countSql = "
    SELECT COUNT(*)
    FROM tbl_child_info ci
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    LEFT JOIN (
      SELECT child_seq, MAX(date_measured) AS last_measured
      FROM tbl_measurement
      GROUP BY child_seq
    ) lm ON lm.child_seq = ci.child_seq
    $whereSql
  ";
  $st = $pdo->prepare($countSql);
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  // --------------------
  // List query
  // --------------------
  $listSql = "
    SELECT
      ci.child_seq,
      ci.c_firstname,
      ci.c_middlename,
      ci.c_lastname,
      ci.date_birth,
      ci.sex,
      ci.purok,
      b.barangay_name,
      lm.last_measured AS last_updated
    FROM tbl_child_info ci
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    LEFT JOIN (
      SELECT child_seq, MAX(date_measured) AS last_measured
      FROM tbl_measurement
      GROUP BY child_seq
    ) lm ON lm.child_seq = ci.child_seq
    $whereSql
    ORDER BY
      CASE WHEN lm.last_measured IS NULL THEN 0 ELSE 1 END ASC,
      COALESCE(lm.last_measured, '1900-01-01') ASC,
      ci.c_lastname ASC,
      ci.c_firstname ASC
    LIMIT $limit OFFSET $offset
  ";

  $st = $pdo->prepare($listSql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $today = date('Y-m-d');
  $outRows = [];

  foreach ($rows as $r) {
    $dateBirth = $r['date_birth'] ?? null;
    $lastUpdated = $r['last_updated'] ?? null;

    $ageMonths = null;
    $category = '60+ months';
    $scheduleRule = 'Beyond under-5';
    $allowedNow = false;
    $statusLabel = 'Not Eligible';

    if (!empty($dateBirth)) {
      $ageMonths = months_diff($dateBirth, $today);

      if ($ageMonths <= 23) {
        $category = '0-23 months';
        $scheduleRule = 'Monthly';
      } elseif ($ageMonths <= 59) {
        $category = '24-59 months';
        $scheduleRule = 'Quarterly';
      }

      if ($ageMonths <= 59) {
        if (empty($lastUpdated)) {
          $allowedNow = true;
          $statusLabel = 'Ready to Encode';
        } else {
          $monthsSinceLast = months_diff($lastUpdated, $today);

          if ($ageMonths <= 23) {
            $allowedNow = $monthsSinceLast >= 1;
            $statusLabel = $allowedNow ? 'Ready to Encode' : 'Already Measured';
          } else {
            $allowedNow = $monthsSinceLast >= 3;
            $statusLabel = $allowedNow ? 'Ready to Encode' : 'Already Measured';
          }
        }
      }
    }

    // optional filter after computing age/schedule
    if ($filter === 'eligible' && !$allowedNow) {
      continue;
    }
    if ($filter === '023' && $category !== '0-23 months') {
      continue;
    }
    if ($filter === '2459' && $category !== '24-59 months') {
      continue;
    }
    if ($filter === 'measured' && empty($lastUpdated)) {
      continue;
    }

    $outRows[] = [
      "child_seq" => (int)$r['child_seq'],
      "c_firstname" => $r['c_firstname'] ?? '',
      "c_middlename" => $r['c_middlename'] ?? '',
      "c_lastname" => $r['c_lastname'] ?? '',
      "date_birth" => $r['date_birth'] ?? null,
      "sex" => $r['sex'] ?? '',
      "purok" => $r['purok'] ?? '',
      "barangay_name" => $r['barangay_name'] ?? '',
      "last_updated" => $lastUpdated,
      "age_months" => $ageMonths,
      "category" => $category,
      "schedule_rule" => $scheduleRule,
      "allowed_now" => $allowedNow,
      "status_label" => $statusLabel
    ];
  }

  echo json_encode([
    "ok" => true,
    "message" => "OK",
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "filter" => $filter,
    "rows" => array_values($outRows)
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}