<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");
ini_set("display_errors", "0");
error_reporting(E_ALL);

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
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";
require_once __DIR__ . "/../helpers/nutrition_status_helper.php";

function out($code, $payload) {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function get_user_barangay_id(PDO $pdo, $role, $userId) {
  if ($role === 'admin') return 0;

  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([(int)$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    out(403, ["ok" => false, "message" => "No barangay assigned"]);
  }

  return $barangayId;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower((string)($authUser->role ?? 'user'));
  $userId = (int)($authUser->sub ?? 0);

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    out(422, ["ok" => false, "message" => "Invalid JSON body"]);
  }

  $childSeq = (int)($data["child_seq"] ?? 0);
  $dateMeasured = trim((string)($data["date_measured"] ?? ""));
  $assessmentMethod = trim((string)($data["assessment_method"] ?? "Weight + Length/Height"));
  $weight = isset($data["weight"]) && $data["weight"] !== "" ? (float)$data["weight"] : null;
  $height = isset($data["height"]) && $data["height"] !== "" ? (float)$data["height"] : null;
  $muac = isset($data["muac"]) && $data["muac"] !== "" ? (float)$data["muac"] : null;
  $bilateralPitting = trim((string)($data["bilateral_pitting"] ?? "No"));

  if ($childSeq <= 0) {
    out(422, ["ok" => false, "message" => "Invalid child_seq"]);
  }

  if ($dateMeasured === "") {
    out(422, ["ok" => false, "message" => "date_measured is required"]);
  }

  $userBarangayId = get_user_barangay_id($pdo, $role, $userId);

  $where = ["ci.child_seq = ?"];
  $params = [$childSeq];

  if ($role !== 'admin') {
    $where[] = "ci.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  $sql = "
    SELECT
      ci.child_seq,
      ci.sex,
      ci.date_birth,
      ci.barangay_id
    FROM tbl_child_info ci
    WHERE " . implode(" AND ", $where) . "
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $child = $st->fetch(PDO::FETCH_ASSOC);

  if (!$child) {
    out(404, ["ok" => false, "message" => "Child not found"]);
  }

  $dateBirth = trim((string)($child["date_birth"] ?? ""));
  $sex = trim((string)($child["sex"] ?? ""));

  if ($dateBirth === "") {
    out(422, ["ok" => false, "message" => "Child date of birth is missing"]);
  }

  $birth = new DateTime($dateBirth);
  $measured = new DateTime($dateMeasured);

  if ($measured < $birth) {
    out(422, ["ok" => false, "message" => "Date measured cannot be earlier than date of birth"]);
  }

  $diff = $birth->diff($measured);
  $ageMonths = ($diff->y * 12) + $diff->m;
  $ageDays = (int)$diff->days;

  $weightStatus = null;
  $heightStatus = null;
  $ltStatus = null;
  $muacStatus = null;

  if ($assessmentMethod === "Weight + Length/Height") {
    if ($weight !== null && $height !== null) {
      $calc = nh_compute_all_statuses(
        $sex,
        $dateBirth,
        $dateMeasured,
        $weight,
        $height,
        null,
        $bilateralPitting
      );

      $weightStatus = $calc["weight_status"] ?? null;
      $heightStatus = $calc["height_status"] ?? null;
      $ltStatus = $calc["lt_status"] ?? null;
      $muacStatus = $calc["muac_status"] ?? null;
    }
  } elseif ($assessmentMethod === "MUAC") {
    if ($muac !== null) {
      $calc = nh_compute_all_statuses(
        $sex,
        $dateBirth,
        $dateMeasured,
        null,
        null,
        $muac,
        $bilateralPitting
      );

      $weightStatus = $calc["weight_status"] ?? null;
      $heightStatus = $calc["height_status"] ?? null;
      $ltStatus = $calc["lt_status"] ?? null;
      $muacStatus = $calc["muac_status"] ?? null;
    }
  } else {
    out(422, ["ok" => false, "message" => "Invalid assessment_method"]);
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