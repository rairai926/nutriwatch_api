<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $childSeq = (int)($_GET['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    out(422, ["message" => "Invalid child_seq"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $where = ["ci.child_seq = ?"];
  $params = [$childSeq];

  if ($role !== 'admin') {
    $where[] = "ci.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  $whereSql = "WHERE " . implode(" AND ", $where);

  $sql = "
    SELECT
      ci.child_seq,
      ci.province_id,
      ci.city_id,
      ci.barangay_id,
      ci.purok,
      ci.g_lastname,
      ci.g_firstname,
      ci.g_middlename,
      ci.c_lastname,
      ci.c_firstname,
      ci.c_middlename,
      ci.ip_group,
      ci.sex,
      ci.date_birth,
      ci.disability,
      ci.user_id,
      ci.child_photo_type,
      CASE
        WHEN ci.child_photo IS NOT NULL AND OCTET_LENGTH(ci.child_photo) > 0 THEN 1
        ELSE 0
      END AS has_photo,
      b.barangay_name
    FROM tbl_child_info ci
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    $whereSql
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $child = $st->fetch(PDO::FETCH_ASSOC);

  if (!$child) {
    out(404, ["message" => "Child not found"]);
  }

  $latestSql = "
    SELECT
      m.measure_id,
      m.child_seq,
      m.user_id,
      m.date_measured,
      m.weight,
      m.height,
      m.muac,
      m.age_months,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.bilateral_pitting,
      m.is_exported_excel,
      m.excel_exported_at
    FROM tbl_measurement m
    WHERE m.child_seq = ?
    ORDER BY m.date_measured DESC, m.measure_id DESC
    LIMIT 1
  ";

  $st = $pdo->prepare($latestSql);
  $st->execute([$childSeq]);
  $latest = $st->fetch(PDO::FETCH_ASSOC);

  if (!$latest) {
    $latest = null;
  } else {
    $latest['is_exported_excel'] = (int)($latest['is_exported_excel'] ?? 0);

    if (!empty($child['date_birth']) && !empty($latest['date_measured'])) {
      $birth = new DateTime($child['date_birth']);
      $measured = new DateTime($latest['date_measured']);
      $diff = $birth->diff($measured);
      $latest['age_days'] = (int)$diff->days;
    } else {
      $latest['age_days'] = null;
    }
  }

  $child['has_photo'] = (int)($child['has_photo'] ?? 0);
  $child['photo_url'] = $child['has_photo']
    ? "/child/get_child_photo.php?child_seq=" . (int)$child['child_seq']
    : null;

  out(200, [
    "child" => $child,
    "latest_measurement" => $latest
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}