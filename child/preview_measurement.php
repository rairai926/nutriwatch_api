<?php


require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";
require_once __DIR__ . "/../helpers/nutrition_status_helper.php";

function out($code, $payload) {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    out(422, [
      "ok" => false,
      "message" => "Invalid JSON body"
    ]);
  }

  $childSeq = (int)($data["child_seq"] ?? 0);
  $dateMeasured = trim((string)($data["date_measured"] ?? ""));
  $assessmentMethod = trim((string)($data["assessment_method"] ?? "Weight + Length/Height"));

  $weight = isset($data["weight"]) && $data["weight"] !== "" ? (float)$data["weight"] : null;
  $height = isset($data["height"]) && $data["height"] !== "" ? (float)$data["height"] : null;
  $muac = isset($data["muac"]) && $data["muac"] !== "" ? (float)$data["muac"] : null;
  $bilateralPitting = trim((string)($data["bilateral_pitting"] ?? "No"));

  if ($childSeq <= 0) {
    out(422, [
      "ok" => false,
      "message" => "Invalid child_seq"
    ]);
  }

  if ($dateMeasured === "") {
    out(422, [
      "ok" => false,
      "message" => "Date measured is required"
    ]);
  }

  if ($assessmentMethod !== "Weight + Length/Height" && $assessmentMethod !== "MUAC") {
    out(422, [
      "ok" => false,
      "message" => "Invalid assessment method"
    ]);
  }

  $stmt = $pdo->prepare("
    SELECT
      child_seq,
      sex,
      date_birth
    FROM tbl_child_info
    WHERE child_seq = ?
    LIMIT 1
  ");
  $stmt->execute([$childSeq]);
  $child = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$child) {
    out(404, [
      "ok" => false,
      "message" => "Child not found"
    ]);
  }

  $sex = trim((string)($child["sex"] ?? ""));
  $dateBirth = trim((string)($child["date_birth"] ?? ""));

  if ($dateBirth === "") {
    out(422, [
      "ok" => false,
      "message" => "Child date of birth is missing"
    ]);
  }

  $birth = new DateTime($dateBirth);
  $measured = new DateTime($dateMeasured);

  if ($measured < $birth) {
    out(422, [
      "ok" => false,
      "message" => "Date measured cannot be earlier than date of birth"
    ]);
  }

  $diff = $birth->diff($measured);
  $ageMonths = ($diff->y * 12) + $diff->m;
  $ageDays = (int)$diff->days;

  $weightStatus = '';
  $heightStatus = '';
  $ltStatus = '';
  $muacStatus = '';

  if ($assessmentMethod === "Weight + Length/Height") {
    if ($weight === null || $height === null) {
      out(200, [
        "ok" => true,
        "age_months" => $ageMonths,
        "age_days" => $ageDays,
        "weight_status" => '',
        "height_status" => '',
        "lt_status" => '',
        "muac_status" => ''
      ]);
    }

    $calc = nh_compute_all_statuses(
      $sex,
      $dateBirth,
      $dateMeasured,
      $weight,
      $height,
      null,
      $bilateralPitting
    );

    $weightStatus = $calc["weight_status"] ?? '';
    $heightStatus = $calc["height_status"] ?? '';
    $ltStatus = $calc["lt_status"] ?? '';
    $muacStatus = $calc["muac_status"] ?? '';
  }

  if ($assessmentMethod === "MUAC") {
    if ($muac === null) {
      out(200, [
        "ok" => true,
        "age_months" => $ageMonths,
        "age_days" => $ageDays,
        "weight_status" => '',
        "height_status" => '',
        "lt_status" => '',
        "muac_status" => ''
      ]);
    }

    $calc = nh_compute_all_statuses(
      $sex,
      $dateBirth,
      $dateMeasured,
      null,
      null,
      $muac,
      $bilateralPitting
    );

    $weightStatus = $calc["weight_status"] ?? '';
    $heightStatus = $calc["height_status"] ?? '';
    $ltStatus = $calc["lt_status"] ?? '';
    $muacStatus = $calc["muac_status"] ?? '';
  }

  out(200, [
    "ok" => true,
    "message" => "Preview computed successfully",
    "age_months" => $ageMonths,
    "age_days" => $ageDays,
    "weight_status" => $weightStatus,
    "height_status" => $heightStatus,
    "lt_status" => $ltStatus,
    "muac_status" => $muacStatus
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Preview failed",
    "error" => $e->getMessage()
  ]);
}