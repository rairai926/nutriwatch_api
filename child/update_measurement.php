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
header("Access-Control-Allow-Methods: POST, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  echo json_encode(["message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";
require_once __DIR__ . "/../helpers/nutrition_status_helper.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
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
    out(422, ["message" => "Invalid measure_id"]);
  }

  if ($dateMeasured === '') {
    out(422, ["message" => "Date measured is required"]);
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

  $sql = "
    SELECT
      m.measure_id,
      m.child_seq,
      ci.barangay_id,
      ci.sex,
      ci.date_birth
    FROM tbl_measurement m
    JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE m.measure_id = ?
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$measureId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    out(404, ["message" => "Measurement not found"]);
  }

  if ($role !== 'admin' && (int)$row['barangay_id'] !== $userBarangayId) {
    out(403, ["message" => "Forbidden"]);
  }

  if (empty($row['date_birth'])) {
    out(422, ["message" => "Child birthday is required before updating measurement"]);
  }

  if ($assessmentMethod === 'Weight + Length/Height') {
    if ($weight === null || $height === null) {
      out(422, ["message" => "Weight and Height/Length are required for this method"]);
    }
  }

  if ($assessmentMethod === 'MUAC') {
    if ($muac === null) {
      out(422, ["message" => "MUAC is required for this method"]);
    }
  }

  $ageMonthsPreview = nh_compute_age_months($row['date_birth'], $dateMeasured);
  if ($height !== null) {
    if ($ageMonthsPreview <= 23) {
      if ($height < 45 || $height > 110) {
        out(422, ["message" => "For 0–23 months, length must be between 45 and 110 cm"]);
      }
    } else {
      if ($height < 65 || $height > 120) {
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