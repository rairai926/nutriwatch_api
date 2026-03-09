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

  $childSeq = (int)($data['child_seq'] ?? 0);
  $dateMeasured = trim((string)($data['date_measured'] ?? ''));
  $assessmentMethod = trim((string)($data['assessment_method'] ?? 'Weight + Length/Height'));

  $weight = ($data['weight'] !== '' && $data['weight'] !== null) ? (float)$data['weight'] : null;
  $height = ($data['height'] !== '' && $data['height'] !== null) ? (float)$data['height'] : null;
  $muac = ($data['muac'] !== '' && $data['muac'] !== null) ? (float)$data['muac'] : null;
  $bilateralPitting = trim((string)($data['bilateral_pitting'] ?? 'No'));

  if ($childSeq <= 0) {
    out(422, ["message" => "Invalid child_seq"]);
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

  $checkSql = "
    SELECT child_seq, barangay_id, sex, date_birth
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
    out(404, ["message" => "Child not found"]);
  }

  if (empty($child['date_birth'])) {
    out(422, ["message" => "Child birthday is required before adding measurement"]);
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

  $ageMonthsPreview = nh_compute_age_months($child['date_birth'], $dateMeasured);
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

  $dup = $pdo->prepare("
    SELECT measure_id
    FROM tbl_measurement
    WHERE child_seq = ? AND date_measured = ?
    LIMIT 1
  ");
  $dup->execute([$childSeq, $dateMeasured]);
  if ($dup->fetchColumn()) {
    out(409, ["message" => "A measurement already exists for this date"]);
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
        weight_status,
        height_status,
        lt_status,
        muac_status,
        bilateral_pitting
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
        :weight_status,
        :height_status,
        :lt_status,
        :muac_status,
        :bilateral_pitting
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
    ':weight_status' => $weightStatus,
    ':height_status' => $heightStatus,
    ':lt_status' => $ltStatus,
    ':muac_status' => $muacStatus,
    ':bilateral_pitting' => $bilateralPitting
  ]);

  out(201, [
    "message" => "Measurement added successfully",
    "measure_id" => (int)$pdo->lastInsertId(),
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