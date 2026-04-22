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

  $childSeq = (int)($data['child_seq'] ?? 0);
  $dateMeasured = trim((string)($data['date_measured'] ?? ''));
  $assessmentMethod = trim((string)($data['assessment_method'] ?? 'Weight + Length/Height'));

  $weight = ($data['weight'] !== '' && $data['weight'] !== null) ? (float)$data['weight'] : null;
  $height = ($data['height'] !== '' && $data['height'] !== null) ? (float)$data['height'] : null;
  $muac = ($data['muac'] !== '' && $data['muac'] !== null) ? (float)$data['muac'] : null;
  $bilateralPitting = trim((string)($data['bilateral_pitting'] ?? 'No'));

  if ($childSeq <= 0) {
    audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', null, 'Invalid child_seq');
    out(422, ["message" => "Invalid child_seq"]);
  }

  if ($dateMeasured === '') {
    audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'Date measured is required');
    out(422, ["message" => "Date measured is required"]);
  }

  $dateObj = DateTime::createFromFormat('Y-m-d', $dateMeasured);
  if (!$dateObj || $dateObj->format('Y-m-d') !== $dateMeasured) {
    audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'Invalid date format');
    out(422, ["message" => "Invalid date_measured format. Use YYYY-MM-DD"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      audit_log($pdo, $userId, 'MEASUREMENT_ADD_DENIED', 'tbl_measurement', (string)$childSeq, 'No barangay assigned');
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $checkSql = "
    SELECT child_seq, barangay_id, sex, date_birth, c_firstname, c_middlename, c_lastname
    FROM tbl_child_info
    WHERE child_seq = ?
  ";
  $checkParams = [$childSeq];

  if ($role !== 'admin') {
    $checkSql .= " AND barangay_id = ?";
    $checkParams[] = $userBarangayId;
  }

  $checkSql .= " LIMIT 1";
  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);
  $child = $st->fetch(PDO::FETCH_ASSOC);

  if (!$child) {
    audit_log($pdo, $userId, 'MEASUREMENT_ADD_DENIED', 'tbl_measurement', (string)$childSeq, 'Child not found or outside barangay');
    out(404, ["message" => "Child not found"]);
  }

  if (empty($child['date_birth'])) {
    audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'Child birthday missing');
    out(422, ["message" => "Child birthday is required before adding measurement"]);
  }

  if ($assessmentMethod === 'Weight + Length/Height') {
    if ($weight === null || $height === null) {
      audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'Weight and height required');
      out(422, ["message" => "Weight and Height/Length are required for this method"]);
    }
  }

  if ($assessmentMethod === 'MUAC') {
    if ($muac === null) {
      audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'MUAC required');
      out(422, ["message" => "MUAC is required for this method"]);
    }
  }

  $ageMonthsPreview = nh_compute_age_months($child['date_birth'], $dateMeasured);
  if ($height !== null) {
    if ($ageMonthsPreview <= 23) {
      if ($height < 45 || $height > 110) {
        audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'Invalid length for 0-23 months');
        out(422, ["message" => "For 0–23 months, length must be between 45 and 110 cm"]);
      }
    } else {
      if ($height < 65 || $height > 120) {
        audit_log($pdo, $userId, 'MEASUREMENT_ADD_FAILED', 'tbl_measurement', (string)$childSeq, 'Invalid height for 24+ months');
        out(422, ["message" => "For 24+ months, height must be between 65 and 120 cm"]);
      }
    }
  }

  $statuses = nh_compute_all_statuses(
    $child['sex'],
    $child['date_birth'],
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

  // Block new measurement based on monitoring rule:
  // 0–23 months  => monthly
  // 24–59 months => quarterly (every 3 months)
  $lastMeasurement = $pdo->prepare("
    SELECT measure_id, date_measured
    FROM tbl_measurement
    WHERE child_seq = ?
    ORDER BY date_measured DESC, measure_id DESC
    LIMIT 1
  ");
  $lastMeasurement->execute([$childSeq]);
  $lastRow = $lastMeasurement->fetch(PDO::FETCH_ASSOC);

  if ($lastRow) {
    $lastDateObj = DateTime::createFromFormat('Y-m-d', $lastRow['date_measured']);

    if ($lastDateObj) {
      $allowedDateObj = clone $lastDateObj;

      if ($ageMonths <= 23) {
        $allowedDateObj->modify('+1 month');
        $ruleLabel = 'monthly';
      } elseif ($ageMonths <= 59) {
        $allowedDateObj->modify('+3 months');
        $ruleLabel = 'quarterly';
      } else {
        $ruleLabel = 'beyond under-5';
      }

      if ($dateObj < $allowedDateObj) {
        $lastDate = $lastDateObj->format('Y-m-d');
        $allowedDate = $allowedDateObj->format('Y-m-d');

        audit_log(
          $pdo,
          $userId,
          'MEASUREMENT_ADD_BLOCKED',
          'tbl_measurement',
          (string)$childSeq,
          "Measurement too soon ({$ruleLabel}). Last: {$lastDate}, Next allowed: {$allowedDate}"
        );

        out(409, [
          "message" => "This child follows {$ruleLabel} monitoring. A new measurement can only be added on or after {$allowedDate}"
        ]);
      }
    }
  }

  $sql = "
    INSERT INTO tbl_measurement
      (
        child_seq,
        user_id,
        date_measured,
        weight,
        height,
        muac,
        age_months,
        age_days,
        weight_status,
        height_status,
        lt_status,
        muac_status,
        bilateral_pitting,
        assessment_method
      )
    VALUES
      (
        :child_seq,
        :user_id,
        :date_measured,
        :weight,
        :height,
        :muac,
        :age_months,
        :age_days,
        :weight_status,
        :height_status,
        :lt_status,
        :muac_status,
        :bilateral_pitting,
        :assessment_method
      )
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':child_seq' => $childSeq,
    ':user_id' => $userId,
    ':date_measured' => $dateMeasured,
    ':weight' => $weight,
    ':height' => $height,
    ':muac' => $muac,
    ':age_months' => $ageMonths,
    ':age_days' => $ageDays,
    ':weight_status' => $weightStatus,
    ':height_status' => $heightStatus,
    ':lt_status' => $ltStatus,
    ':muac_status' => $muacStatus,
    ':bilateral_pitting' => $bilateralPitting,
    ':assessment_method' => $assessmentMethod
  ]);

  $measureId = (int)$pdo->lastInsertId();
  $childName = trim(implode(' ', array_filter([
    $child['c_firstname'] ?? '',
    $child['c_middlename'] ?? '',
    $child['c_lastname'] ?? ''
  ])));

  audit_log(
    $pdo,
    $userId,
    'MEASUREMENT_ADDED',
    'tbl_measurement',
    (string)$measureId,
    "Added measurement for child_seq={$childSeq}" . ($childName !== '' ? " ({$childName})" : '') . " on {$dateMeasured}"
  );

  out(201, [
    "message" => "Measurement added successfully",
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