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
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $checkSql = "SELECT child_seq FROM tbl_child_info WHERE child_seq = ?";
  $checkParams = [$childSeq];

  if ($role !== 'admin') {
    $checkSql .= " AND barangay_id = ?";
    $checkParams[] = $userBarangayId;
  }

  $checkSql .= " LIMIT 1";
  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);

  if (!$st->fetchColumn()) {
    out(404, ["message" => "Child not found"]);
  }

  $sql = "
    SELECT
      m.measure_id,
      m.child_seq,
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
      m.excel_exported_at,
      m.user_id AS encoded_by,

      u.users_id,
      u.role AS encoded_by_role,
      CONCAT(
        COALESCE(u.firstname, ''),
        CASE WHEN COALESCE(u.middlename, '') <> '' THEN CONCAT(' ', u.middlename) ELSE '' END,
        CASE WHEN COALESCE(u.lastname, '') <> '' THEN CONCAT(' ', u.lastname) ELSE '' END
      ) AS encoded_by_name

    FROM tbl_measurement m
    LEFT JOIN tbl_users u
      ON u.users_id = m.user_id
    WHERE m.child_seq = ?
    ORDER BY m.date_measured DESC, m.measure_id DESC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$childSeq]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$row) {
    $row['encoded_by_name'] = trim((string)($row['encoded_by_name'] ?? ''));
    if ($row['encoded_by_name'] === '') {
      $row['encoded_by_name'] = 'Unknown User';
    }

    $row['is_exported_excel'] = (int)($row['is_exported_excel'] ?? 0);
  }
  unset($row);

  out(200, ["rows" => $rows]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}