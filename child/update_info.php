<?php
ob_start();
session_start();
header("Content-Type: application/json; charset=utf-8");

// CORS
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
  if ($childSeq <= 0) {
    audit_log($pdo, $userId, 'CHILD_INFO_UPDATE_FAILED', 'tbl_child_info', null, 'Invalid child_seq');
    out(422, ["message" => "Invalid child_seq"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);
    if ($userBarangayId <= 0) {
      audit_log($pdo, $userId, 'CHILD_INFO_UPDATE_DENIED', 'tbl_child_info', (string)$childSeq, 'No barangay assigned');
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $checkSql = "
    SELECT child_seq, c_firstname, c_middlename, c_lastname, barangay_id
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
  $existing = $st->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    audit_log($pdo, $userId, 'CHILD_INFO_UPDATE_DENIED', 'tbl_child_info', (string)$childSeq, 'Child not found or outside barangay');
    out(404, ["message" => "Child not found"]);
  }

  $newFirst = trim($data['c_firstname'] ?? '');
  $newMiddle = trim($data['c_middlename'] ?? '');
  $newLast = trim($data['c_lastname'] ?? '');

  $sql = "
    UPDATE tbl_child_info
    SET
      c_firstname = :c_firstname,
      c_middlename = :c_middlename,
      c_lastname = :c_lastname,
      g_firstname = :g_firstname,
      g_middlename = :g_middlename,
      g_lastname = :g_lastname,
      purok = :purok,
      sex = :sex,
      date_birth = :date_birth,
      ip_group = :ip_group,
      disability = :disability
    WHERE child_seq = :child_seq
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':c_firstname' => $newFirst,
    ':c_middlename' => $newMiddle,
    ':c_lastname' => $newLast,
    ':g_firstname' => trim($data['g_firstname'] ?? ''),
    ':g_middlename' => trim($data['g_middlename'] ?? ''),
    ':g_lastname' => trim($data['g_lastname'] ?? ''),
    ':purok' => trim($data['purok'] ?? ''),
    ':sex' => trim($data['sex'] ?? ''),
    ':date_birth' => trim($data['date_birth'] ?? '') ?: null,
    ':ip_group' => trim($data['ip_group'] ?? ''),
    ':disability' => trim($data['disability'] ?? ''),
    ':child_seq' => $childSeq
  ]);

  $oldName = trim(implode(' ', array_filter([
    $existing['c_firstname'] ?? '',
    $existing['c_middlename'] ?? '',
    $existing['c_lastname'] ?? ''
  ])));

  $newName = trim(implode(' ', array_filter([$newFirst, $newMiddle, $newLast])));

  audit_log(
    $pdo,
    $userId,
    'CHILD_INFO_UPDATED',
    'tbl_child_info',
    (string)$childSeq,
    "Updated child info: {$oldName} -> {$newName}"
  );

  out(200, ["message" => "Child information updated"]);
} catch (Throwable $e) {
  audit_log($pdo ?? new PDO('sqlite::memory:'), $userId ?? null, 'CHILD_INFO_UPDATE_FAILED', 'tbl_child_info', isset($childSeq) ? (string)$childSeq : null, $e->getMessage());
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}