<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$allowedOrigins = [
  'http://localhost:3000',
  'http://127.0.0.1:3000',
  'https://nutriwatch.com',
  'http://192.168.1.36:3000'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/nutrition_status_helper.php';

function out($code, $payload) {
  if (ob_get_length()) {
    ob_clean();
  }
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_body() {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    return [];
  }
  $data = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    out(422, ['ok' => false, 'message' => 'Invalid JSON input', 'error' => json_last_error_msg()]);
  }
  return $data;
}

function get_user_barangay_id(PDO $pdo, $role, $userId) {
  if ($role === 'admin') return 0;
  $st = $pdo->prepare('SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1');
  $st->execute([(int)$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);
  if ($barangayId <= 0) {
    out(403, ['ok' => false, 'message' => 'No barangay assigned']);
  }
  return $barangayId;
}

function valid_date_or_fail($value, $fieldLabel) {
  $dt = DateTime::createFromFormat('Y-m-d', $value);
  if (!$dt || $dt->format('Y-m-d') !== $value) {
    out(422, ['ok' => false, 'message' => "$fieldLabel must be in YYYY-MM-DD format"]);
  }
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'message' => 'Method not allowed']);
  }

  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower((string)($authUser->role ?? 'user'));
  $userId = (int)($authUser->sub ?? 0);
  $data = read_json_body();

  $measureId = (int)($data['measure_id'] ?? 0);
  $dateMeasured = trim((string)($data['date_measured'] ?? ''));
  $assessmentMethod = trim((string)($data['assessment_method'] ?? 'Weight + Length/Height'));
  $weight = ($data['weight'] ?? '') !== '' && ($data['weight'] ?? null) !== null ? (float)$data['weight'] : null;
  $height = ($data['height'] ?? '') !== '' && ($data['height'] ?? null) !== null ? (float)$data['height'] : null;
  $muac = ($data['muac'] ?? '') !== '' && ($data['muac'] ?? null) !== null ? (float)$data['muac'] : null;
  $bilateralPitting = trim((string)($data['bilateral_pitting'] ?? 'No'));

  if ($measureId <= 0) {
    out(422, ['ok' => false, 'message' => 'Invalid measure_id']);
  }
  if ($dateMeasured === '') {
    out(422, ['ok' => false, 'message' => 'Date measured is required']);
  }
  valid_date_or_fail($dateMeasured, 'Date measured');

  if (!in_array($assessmentMethod, ['Weight + Length/Height', 'MUAC'], true)) {
    out(422, ['ok' => false, 'message' => 'Invalid assessment method']);
  }

  $barangayId = get_user_barangay_id($pdo, $role, $userId);

  $sql = '
    SELECT
      m.measure_id,
      m.child_seq,
      ci.barangay_id,
      ci.sex,
      ci.date_birth
    FROM tbl_measurement m
    INNER JOIN tbl_child_info ci ON ci.child_seq = m.child_seq
    WHERE m.measure_id = ?
  ';
  $params = [$measureId];
  if ($role !== 'admin') {
    $sql .= ' AND ci.barangay_id = ?';
    $params[] = $barangayId;
  }
  $sql .= ' LIMIT 1';

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    out(404, ['ok' => false, 'message' => 'Measurement not found']);
  }
  if (empty($row['date_birth'])) {
    out(422, ['ok' => false, 'message' => 'Child birthday is required before updating measurement']);
  }

  if ($assessmentMethod === 'Weight + Length/Height' && ($weight === null || $height === null)) {
    out(422, ['ok' => false, 'message' => 'Weight and Height/Length are required for this method']);
  }
  if ($assessmentMethod === 'MUAC' && $muac === null) {
    out(422, ['ok' => false, 'message' => 'MUAC is required for this method']);
  }

  $ageMonthsPreview = nh_compute_age_months($row['date_birth'], $dateMeasured);
  if ($height !== null) {
    if ($ageMonthsPreview <= 23 && ($height < 45 || $height > 110)) {
      out(422, ['ok' => false, 'message' => 'For 0–23 months, length must be between 45 and 110 cm']);
    }
    if ($ageMonthsPreview > 23 && ($height < 65 || $height > 120)) {
      out(422, ['ok' => false, 'message' => 'For 24+ months, height must be between 65 and 120 cm']);
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

  $dup = $pdo->prepare('SELECT measure_id FROM tbl_measurement WHERE child_seq = ? AND date_measured = ? AND measure_id <> ? LIMIT 1');
  $dup->execute([(int)$row['child_seq'], $dateMeasured, $measureId]);
  if ($dup->fetchColumn()) {
    out(409, ['ok' => false, 'message' => 'Another measurement already exists for this date']);
  }

  $updateSql = '
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
  ';

  $st = $pdo->prepare($updateSql);
  $st->execute([
    ':user_id' => $userId,
    ':date_measured' => $dateMeasured,
    ':weight' => $weight,
    ':height' => $height,
    ':muac' => $muac,
    ':age_months' => $statuses['age_months'],
    ':weight_status' => $statuses['weight_status'],
    ':height_status' => $statuses['height_status'],
    ':lt_status' => $statuses['lt_status'],
    ':muac_status' => $statuses['muac_status'],
    ':bilateral_pitting' => $bilateralPitting,
    ':measure_id' => $measureId,
  ]);

  out(200, [
    'ok' => true,
    'message' => 'Measurement updated successfully',
    'measure_id' => $measureId,
    'age_months' => $statuses['age_months'],
    'age_days' => $statuses['age_days'],
    'weight_status' => $statuses['weight_status'],
    'height_status' => $statuses['height_status'],
    'lt_status' => $statuses['lt_status'],
    'muac_status' => $statuses['muac_status'],
    'user_id' => $userId
  ]);
} catch (Throwable $e) {
  out(500, ['ok' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
