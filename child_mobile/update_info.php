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
    out(422, [
      'ok' => false,
      'message' => 'Invalid JSON input',
      'error' => json_last_error_msg()
    ]);
  }

  return $data;
}

function get_user_barangay_id(PDO $pdo, $role, $userId) {
  if ($role === 'admin') {
    return 0;
  }

  $st = $pdo->prepare('SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1');
  $st->execute([(int)$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    out(403, ['ok' => false, 'message' => 'No barangay assigned']);
  }

  return $barangayId;
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'message' => 'Method not allowed']);
  }

  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower((string)($authUser->role ?? 'user'));
  $userId = (int)($authUser->sub ?? 0);
  $data = read_json_body();

  $childSeq = (int)($data['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    out(422, ['ok' => false, 'message' => 'Invalid child_seq']);
  }

  $barangayId = get_user_barangay_id($pdo, $role, $userId);

  $checkSql = 'SELECT child_seq FROM tbl_child_info WHERE child_seq = ?';
  $checkParams = [$childSeq];
  if ($role !== 'admin') {
    $checkSql .= ' AND barangay_id = ?';
    $checkParams[] = $barangayId;
  }
  $checkSql .= ' LIMIT 1';

  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);
  if (!$st->fetchColumn()) {
    out(404, ['ok' => false, 'message' => 'Child not found']);
  }

  $sex = trim((string)($data['sex'] ?? ''));
  if ($sex !== '' && !in_array(strtolower($sex), ['male', 'female'], true)) {
    out(422, ['ok' => false, 'message' => 'Sex must be Male or Female']);
  }

  $dateBirth = trim((string)($data['date_birth'] ?? ''));
  if ($dateBirth !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $dateBirth);
    if (!$dt || $dt->format('Y-m-d') !== $dateBirth) {
      out(422, ['ok' => false, 'message' => 'date_birth must be in YYYY-MM-DD format']);
    }
  } else {
    $dateBirth = null;
  }

  $sql = '
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
  ';

  $st = $pdo->prepare($sql);
  $st->execute([
    ':c_firstname' => trim((string)($data['c_firstname'] ?? '')),
    ':c_middlename' => trim((string)($data['c_middlename'] ?? '')),
    ':c_lastname' => trim((string)($data['c_lastname'] ?? '')),
    ':g_firstname' => trim((string)($data['g_firstname'] ?? '')),
    ':g_middlename' => trim((string)($data['g_middlename'] ?? '')),
    ':g_lastname' => trim((string)($data['g_lastname'] ?? '')),
    ':purok' => trim((string)($data['purok'] ?? '')),
    ':sex' => $sex,
    ':date_birth' => $dateBirth,
    ':ip_group' => trim((string)($data['ip_group'] ?? '')),
    ':disability' => trim((string)($data['disability'] ?? '')),
    ':child_seq' => $childSeq,
  ]);

  out(200, [
    'ok' => true,
    'message' => 'Child information updated successfully',
    'child_seq' => $childSeq
  ]);
} catch (Throwable $e) {
  out(500, [
    'ok' => false,
    'message' => 'Server error',
    'error' => $e->getMessage()
  ]);
}
