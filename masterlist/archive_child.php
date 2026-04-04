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
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

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
  $authUser = authenticate(['admin']);
  $userId = (int)($authUser->sub ?? 0);

  $input = json_decode(file_get_contents("php://input"), true);
  if (!is_array($input)) $input = [];

  $childSeq = (int)($input['child_seq'] ?? 0);
  $reason = trim((string)($input['reason'] ?? 'Manually archived by admin'));

  if ($childSeq <= 0) {
    out(422, ["ok" => false, "message" => "Invalid child_seq"]);
  }

  $st = $pdo->prepare("
    SELECT *
    FROM tbl_child_info
    WHERE child_seq = ?
    LIMIT 1
  ");
  $st->execute([$childSeq]);
  $r = $st->fetch(PDO::FETCH_ASSOC);

  if (!$r) {
    out(404, ["ok" => false, "message" => "Child not found"]);
  }

  $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_child_archive WHERE child_seq = ?");
  $check->execute([$childSeq]);
  if ((int)$check->fetchColumn() > 0) {
    out(409, ["ok" => false, "message" => "Child already archived"]);
  }

  $pdo->beginTransaction();

  $insert = $pdo->prepare("
    INSERT INTO tbl_child_archive (
      child_seq,
      province_id,
      city_id,
      barangay_id,
      purok,
      g_lastname,
      g_firstname,
      g_middlename,
      c_lastname,
      c_firstname,
      c_middlename,
      ip_group,
      sex,
      date_birth,
      disability,
      user_id,
      archived_at,
      archive_reason,
      archived_by_user_id
    )
    VALUES (
      :child_seq,
      :province_id,
      :city_id,
      :barangay_id,
      :purok,
      :g_lastname,
      :g_firstname,
      :g_middlename,
      :c_lastname,
      :c_firstname,
      :c_middlename,
      :ip_group,
      :sex,
      :date_birth,
      :disability,
      :user_id,
      NOW(),
      :archive_reason,
      :archived_by_user_id
    )
  ");

  $insert->execute([
    ':child_seq' => $r['child_seq'],
    ':province_id' => $r['province_id'],
    ':city_id' => $r['city_id'],
    ':barangay_id' => $r['barangay_id'],
    ':purok' => $r['purok'],
    ':g_lastname' => $r['g_lastname'],
    ':g_firstname' => $r['g_firstname'],
    ':g_middlename' => $r['g_middlename'],
    ':c_lastname' => $r['c_lastname'],
    ':c_firstname' => $r['c_firstname'],
    ':c_middlename' => $r['c_middlename'],
    ':ip_group' => $r['ip_group'],
    ':sex' => $r['sex'],
    ':date_birth' => $r['date_birth'],
    ':disability' => $r['disability'],
    ':user_id' => $r['user_id'],
    ':archive_reason' => $reason,
    ':archived_by_user_id' => $userId
  ]);

  $del = $pdo->prepare("DELETE FROM tbl_child_info WHERE child_seq = ?");
  $del->execute([$childSeq]);

  $pdo->commit();

  $childName = trim(implode(' ', array_filter([
    $r['c_firstname'] ?? '',
    $r['c_middlename'] ?? '',
    $r['c_lastname'] ?? ''
  ])));

  audit_log(
    $pdo,
    $userId,
    'CHILD_MANUALLY_ARCHIVED',
    'tbl_child_archive',
    (string)$childSeq,
    "Manually archived child_seq={$childSeq}" . ($childName !== '' ? " ({$childName})" : '') . ". Reason: {$reason}"
  );

  out(200, [
    "ok" => true,
    "message" => "Child archived successfully."
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}