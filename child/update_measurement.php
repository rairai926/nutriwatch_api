<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";
require_once __DIR__ . "/../helpers/nutrition_status_helper.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function audit_log(PDO $pdo, ?int $userId, string $action, ?string $targetTable, ?string $targetId, ?string $description): void {
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
      $ip = trim(explode(',', $ip)[0]);
    }

    $st = $pdo->prepare("
      INSERT INTO tbl_audit_logs (user_id, action, target_table, target_id, description, ip_address)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $st->execute([$userId, $action, $targetTable, $targetId, $description, $ip !== '' ? $ip : null]);
  } catch (Throwable $e) {
    error_log("Audit log failed: " . $e->getMessage());
  }
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $measureId = (int)($data['measure_id'] ?? 0);
  $dateMeasured = trim((string)($data['date_measured'] ?? ''));
  $assessmentMethod = trim((string)($data['assessment_method'] ?? 'Weight + Length/Height'));

  $weight = ($data['weight'] !== '' && $data['weight'] !== null) ? (float)$data['weight'] : null;
  $height = ($data['height'] !== '' && $data['height'] !== null) ? (float)$data['height'] : null;
  $muac = ($data['muac'] !== '' && $data['muac'] !== null) ? (float)$data['muac'] : null;
  $bilateralPitting = trim((string)($data['bilateral_pitting'] ?? 'No'));

  if ($measureId <= 0) {
    audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', null, 'Invalid measure_id');
    out(422, ["message" => "Invalid measure_id"]);
  }

  if ($dateMeasured === '') {
    audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'Date measured is required');
    out(422, ["message" => "Date measured is required"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_DENIED', 'tbl_measurement', (string)$measureId, 'No barangay assigned');
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $sql = "
    SELECT
      m.measure_id,
      m.child_seq,
      m.date_measured AS old_date_measured,
      m.weight AS old_weight,
      m.height AS old_height,
      m.muac AS old_muac,
      m.is_exported_excel,
      m.excel_exported_at,
      ci.barangay_id,
      ci.sex,
      ci.date_birth,
      ci.c_firstname,
      ci.c_middlename,
      ci.c_lastname
    FROM tbl_measurement m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE m.measure_id = ?
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$measureId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'Measurement not found');
    out(404, ["message" => "Measurement not found"]);
  }

  $today = new DateTime(date('Y-m-d'));
  $measuredDate = new DateTime($row['old_date_measured']);
  $diffDays = (int)$measuredDate->diff($today)->format('%a');

  $isExported = !empty($row['is_exported_excel']) || !empty($row['excel_exported_at']);

  if ($isExported) {
    audit_log(
      $pdo,
      $userId,
      'MEASUREMENT_UPDATE_DENIED',
      'tbl_measurement',
      (string)$measureId,
      'Measurement already exported to Excel'
    );
    out(403, ["message" => "Measurement can no longer be edited because it was already exported to Excel"]);
  }

  if ($measuredDate < $today && $diffDays > 15) {
    audit_log(
      $pdo,
      $userId,
      'MEASUREMENT_UPDATE_DENIED',
      'tbl_measurement',
      (string)$measureId,
      'Measurement edit window expired'
    );
    out(403, ["message" => "Measurement can only be edited within 15 days from the measurement date"]);
  }

  if ($role !== 'admin' && (int)$row['barangay_id'] !== $userBarangayId) {
    audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_DENIED', 'tbl_measurement', (string)$measureId, 'Forbidden outside barangay');
    out(403, ["message" => "Forbidden"]);
  }

  if (empty($row['date_birth'])) {
    audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'Child birthday missing');
    out(422, ["message" => "Child birthday is required before updating measurement"]);
  }

  if ($assessmentMethod === 'Weight + Length/Height') {
    if ($weight === null || $height === null) {
      audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'Weight and height required');
      out(422, ["message" => "Weight and Height/Length are required for this method"]);
    }
  }

  if ($assessmentMethod === 'MUAC') {
    if ($muac === null) {
      audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'MUAC required');
      out(422, ["message" => "MUAC is required for this method"]);
    }
  }

  $ageMonthsPreview = nh_compute_age_months($row['date_birth'], $dateMeasured);
  if ($height !== null) {
    if ($ageMonthsPreview <= 23) {
      if ($height < 45 || $height > 110) {
        audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'Invalid length for 0-23 months');
        out(422, ["message" => "For 0–23 months, length must be between 45 and 110 cm"]);
      }
    } else {
      if ($height < 65 || $height > 120) {
        audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_FAILED', 'tbl_measurement', (string)$measureId, 'Invalid height for 24+ months');
        out(422, ["message" => "For 24+ months, height must be between 65 and 120 cm"]);
      }
    }
  }

  $statuses = nh_compute_all_statuses(
    $row['sex'],
    $row['date_birth'],
    $dateMeasured,
    $weight,
    $height,
    $muac,
    $bilateralPitting
  );

  $ageMonths = $statuses['age_months'];
  $ageDays = $statuses['age_days'];
  $weightStatus = $statuses['weight_status'];
  $heightStatus = $statuses['height_status'];
  $ltStatus = $statuses['lt_status'];
  $muacStatus = $statuses['muac_status'];

  $dup = $pdo->prepare("
    SELECT measure_id
    FROM tbl_measurement
    WHERE child_seq = ? AND date_measured = ? AND measure_id <> ?
    LIMIT 1
  ");
  $dup->execute([(int)$row['child_seq'], $dateMeasured, $measureId]);
  if ($dup->fetchColumn()) {
    audit_log($pdo, $userId, 'MEASUREMENT_UPDATE_DUPLICATE', 'tbl_measurement', (string)$measureId, "Duplicate target date {$dateMeasured}");
    out(409, ["message" => "Another measurement already exists for this date"]);
  }

  $updateSql = "
    UPDATE tbl_measurement
    SET
      user_id = :user_id,
      date_measured = :date_measured,
      weight = :weight,
      height = :height,
      muac = :muac,
      age_months = :age_months,
      weight_status = :weight_status,
      height_status = :height_status,
      lt_status = :lt_status,
      muac_status = :muac_status,
      bilateral_pitting = :bilateral_pitting
    WHERE measure_id = :measure_id
  ";

  $st = $pdo->prepare($updateSql);
  $st->execute([
    ':user_id' => $userId,
    ':date_measured' => $dateMeasured,
    ':weight' => $weight,
    ':height' => $height,
    ':muac' => $muac,
    ':age_months' => $ageMonths,
    ':weight_status' => $weightStatus,
    ':height_status' => $heightStatus,
    ':lt_status' => $ltStatus,
    ':muac_status' => $muacStatus,
    ':bilateral_pitting' => $bilateralPitting,
    ':measure_id' => $measureId
  ]);

  $childName = trim(implode(' ', array_filter([
    $row['c_firstname'] ?? '',
    $row['c_middlename'] ?? '',
    $row['c_lastname'] ?? ''
  ])));

  $desc = "Updated measurement for child_seq={$row['child_seq']}";
  if ($childName !== '') $desc .= " ({$childName})";
  $desc .= "; date {$row['old_date_measured']} -> {$dateMeasured}";
  $desc .= "; weight {$row['old_weight']} -> " . ($weight ?? 'null');
  $desc .= "; height {$row['old_height']} -> " . ($height ?? 'null');
  $desc .= "; muac {$row['old_muac']} -> " . ($muac ?? 'null');

  audit_log($pdo, $userId, 'MEASUREMENT_UPDATED', 'tbl_measurement', (string)$measureId, $desc);

  out(200, [
    "message" => "Measurement updated successfully",
    "measure_id" => $measureId,
    "age_months" => $ageMonths,
    "age_days" => $ageDays,
    "weight_status" => $weightStatus,
    "height_status" => $heightStatus,
    "lt_status" => $ltStatus,
    "muac_status" => $muacStatus,
    "user_id" => $userId
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}