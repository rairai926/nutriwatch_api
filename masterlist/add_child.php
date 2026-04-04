<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS
// --------------------
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
    $st->execute([
      $userId,
      $action,
      $targetTable,
      $targetId,
      $description,
      $ip !== '' ? $ip : null
    ]);
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

  $forceSave = !empty($data['force_save']);

  // Child
  $c_lastname   = trim((string)($data['c_lastname'] ?? ''));
  $c_firstname  = trim((string)($data['c_firstname'] ?? ''));
  $c_middlename = trim((string)($data['c_middlename'] ?? ''));

  $sex   = trim((string)($data['sex'] ?? ''));
  $purok = trim((string)($data['purok'] ?? ''));

  // Guardian/Caregiver
  $g_lastname   = trim((string)($data['g_lastname'] ?? ''));
  $g_firstname  = trim((string)($data['g_firstname'] ?? ''));
  $g_middlename = trim((string)($data['g_middlename'] ?? ''));

  // Other details
  $date_birth = trim((string)($data['date_birth'] ?? ''));
  $ip_group   = trim((string)($data['ip_group'] ?? ''));
  $disability = trim((string)($data['disability'] ?? ''));

  if ($c_lastname === '' || $c_firstname === '') {
    audit_log($pdo, $userId, 'CHILD_ADD_FAILED', 'tbl_child_info', null, 'Missing child first or last name');
    out(422, ["ok" => false, "message" => "Child first name and last name are required"]);
  }

  $sexLower = strtolower($sex);
  if (in_array($sexLower, ['m', 'male', 'boy', 'boys'], true)) $sex = 'Male';
  if (in_array($sexLower, ['f', 'female', 'girl', 'girls'], true)) $sex = 'Female';
  if (!in_array($sex, ['Male', 'Female'], true)) {
    audit_log($pdo, $userId, 'CHILD_ADD_FAILED', 'tbl_child_info', null, 'Invalid sex value');
    out(422, ["ok" => false, "message" => "Sex must be Male or Female"]);
  }

  $province_id = 1;
  $city_id = 1;

  $barangay_id = 0;

  if ($role === 'admin') {
    $barangay_id = (int)($data['barangay_id'] ?? 0);
    if ($barangay_id <= 0) {
      audit_log($pdo, $userId, 'CHILD_ADD_FAILED', 'tbl_child_info', null, 'Admin missing barangay_id');
      out(422, ["ok" => false, "message" => "Admin must provide barangay_id"]);
    }
  } else {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $barangay_id = (int)($st->fetchColumn() ?: 0);

    if ($barangay_id <= 0) {
      audit_log($pdo, $userId, 'CHILD_ADD_DENIED', 'tbl_child_info', null, 'No barangay assigned');
      out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }
  }

  if ($date_birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_birth)) {
    audit_log($pdo, $userId, 'CHILD_ADD_FAILED', 'tbl_child_info', null, 'Invalid date_birth format');
    out(422, ["ok" => false, "message" => "date_birth must be YYYY-MM-DD"]);
  }

  // --------------------
  // Duplicate detection
  // --------------------
  $dupSql = "
    SELECT
      ci.child_seq,
      ci.c_firstname,
      ci.c_middlename,
      ci.c_lastname,
      ci.sex,
      ci.date_birth,
      ci.purok,
      b.barangay_name
    FROM tbl_child_info ci
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    WHERE ci.barangay_id = :barangay_id
      AND LOWER(TRIM(ci.c_firstname)) = LOWER(TRIM(:c_firstname))
      AND LOWER(TRIM(ci.c_lastname)) = LOWER(TRIM(:c_lastname))
      AND (
        (:date_birth <> '' AND ci.date_birth = :date_birth_exact)
        OR
        (:date_birth = '')
      )
    ORDER BY ci.child_seq DESC
    LIMIT 10
  ";

  $dupSt = $pdo->prepare($dupSql);
  $dupSt->execute([
    ':barangay_id' => $barangay_id,
    ':c_firstname' => $c_firstname,
    ':c_lastname' => $c_lastname,
    ':date_birth' => $date_birth,
    ':date_birth_exact' => ($date_birth !== '' ? $date_birth : null)
  ]);

  $duplicates = $dupSt->fetchAll(PDO::FETCH_ASSOC);

  if (!$forceSave && !empty($duplicates)) {
    audit_log(
      $pdo,
      $userId,
      'CHILD_ADD_DUPLICATE_WARNING',
      'tbl_child_info',
      null,
      "Duplicate warning for {$c_firstname} {$c_lastname}" . ($date_birth !== '' ? " ({$date_birth})" : '')
    );

    out(409, [
      "ok" => false,
      "message" => "Possible duplicate child record found.",
      "duplicate_warning" => true,
      "duplicates" => array_map(function ($r) {
        return [
          "child_seq" => (int)$r["child_seq"],
          "child_name" => trim(implode(' ', array_filter([
            $r["c_firstname"] ?? '',
            $r["c_middlename"] ?? '',
            $r["c_lastname"] ?? ''
          ]))),
          "sex" => $r["sex"] ?? '',
          "date_birth" => $r["date_birth"] ?? null,
          "purok" => $r["purok"] ?? '',
          "barangay_name" => $r["barangay_name"] ?? ''
        ];
      }, $duplicates)
    ]);
  }

  $sql = "
    INSERT INTO tbl_child_info
      (province_id, city_id, barangay_id, purok,
       g_lastname, g_firstname, g_middlename,
       c_lastname, c_firstname, c_middlename,
       ip_group, sex, date_birth, disability,
       user_id)
    VALUES
      (:province_id, :city_id, :barangay_id, :purok,
       :g_lastname, :g_firstname, :g_middlename,
       :c_lastname, :c_firstname, :c_middlename,
       :ip_group, :sex, :date_birth, :disability,
       :user_id)
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':province_id' => $province_id,
    ':city_id' => $city_id,
    ':barangay_id' => $barangay_id,
    ':purok' => ($purok !== '' ? $purok : null),

    ':g_lastname' => ($g_lastname !== '' ? $g_lastname : null),
    ':g_firstname' => ($g_firstname !== '' ? $g_firstname : null),
    ':g_middlename' => ($g_middlename !== '' ? $g_middlename : null),

    ':c_lastname' => $c_lastname,
    ':c_firstname' => $c_firstname,
    ':c_middlename' => ($c_middlename !== '' ? $c_middlename : null),

    ':ip_group' => ($ip_group !== '' ? $ip_group : null),
    ':sex' => $sex,
    ':date_birth' => ($date_birth !== '' ? $date_birth : null),
    ':disability' => ($disability !== '' ? $disability : null),

    ':user_id' => $userId
  ]);

  $newChildSeq = (int)$pdo->lastInsertId();

  $desc = "Added child {$c_firstname} {$c_lastname}";
  if ($date_birth !== '') $desc .= " (DOB: {$date_birth})";
  $desc .= " in barangay_id={$barangay_id}";
  if ($forceSave && !empty($duplicates)) $desc .= " using force_save after duplicate warning";

  audit_log($pdo, $userId, 'CHILD_ADDED', 'tbl_child_info', (string)$newChildSeq, $desc);

  out(201, [
    "ok" => true,
    "message" => "Child added",
    "child_seq" => $newChildSeq
  ]);
} catch (Throwable $e) {
  error_log("add_child.php error: " . $e->getMessage());
  out(500, ["ok" => false, "message" => "Server error", "error" => $e->getMessage()]);
}