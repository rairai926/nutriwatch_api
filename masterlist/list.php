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

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $c_lastname  = trim((string)($data['c_lastname'] ?? ''));
  $c_firstname = trim((string)($data['c_firstname'] ?? ''));
  $c_middlename = trim((string)($data['c_middlename'] ?? ''));

  $sex = trim((string)($data['sex'] ?? ''));
  $purok = trim((string)($data['purok'] ?? ''));

  // Optional fields (if you want later)
  $date_birth = trim((string)($data['date_birth'] ?? ''));
  $disability = trim((string)($data['disability'] ?? ''));

  if ($c_lastname === '' || $c_firstname === '') {
    out(422, ["ok" => false, "message" => "Child first name and last name are required"]);
  }

  // normalize sex
  $sexLower = strtolower($sex);
  if (in_array($sexLower, ['m', 'male', 'boy', 'boys'], true)) $sex = 'Male';
  if (in_array($sexLower, ['f', 'female', 'girl', 'girls'], true)) $sex = 'Female';

  if (!in_array($sex, ['Male', 'Female'], true)) {
    out(422, ["ok" => false, "message" => "Sex must be Male or Female"]);
  }

  // Resolve barangay scope:
  // - Admin: must send barangay_id
  // - user/bns: force to their barangay_id (ignore client input)
  $barangay_id = 0;
  $province_id = null;
  $city_id = null;

  if ($role === 'admin') {
    $barangay_id = (int)($data['barangay_id'] ?? 0);
    if ($barangay_id <= 0) {
      out(422, ["ok" => false, "message" => "Admin must provide barangay_id"]);
    }

    // If you have province_id/city_id in POST, accept them
    if (isset($data['province_id'])) $province_id = (int)$data['province_id'];
    if (isset($data['city_id'])) $city_id = (int)$data['city_id'];
  } else {
    $st = $pdo->prepare("SELECT province_id, city_id, barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    $barangay_id = (int)($u['barangay_id'] ?? 0);
    if ($barangay_id <= 0) {
      out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }

    // Try to carry these if your tbl_child_info requires them
    $province_id = isset($u['province_id']) ? (int)$u['province_id'] : null;
    $city_id = isset($u['city_id']) ? (int)$u['city_id'] : null;
  }

  // Insert into tbl_child_info
  // NOTE: If your tbl_child_info has more NOT NULL columns, add them here.
  $sql = "
    INSERT INTO tbl_child_info
      (province_id, city_id, barangay_id, purok,
       c_lastname, c_firstname, c_middlename,
       sex, date_birth, disability,
       user_id)
    VALUES
      (:province_id, :city_id, :barangay_id, :purok,
       :c_lastname, :c_firstname, :c_middlename,
       :sex, :date_birth, :disability,
       :user_id)
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':province_id' => $province_id,
    ':city_id' => $city_id,
    ':barangay_id' => $barangay_id,
    ':purok' => $purok ?: null,

    ':c_lastname' => $c_lastname,
    ':c_firstname' => $c_firstname,
    ':c_middlename' => $c_middlename ?: null,

    ':sex' => $sex,
    ':date_birth' => ($date_birth !== '' ? $date_birth : null),
    ':disability' => ($disability !== '' ? $disability : null),

    ':user_id' => $userId
  ]);

  out(201, [
    "ok" => true,
    "message" => "Child added",
    "child_seq" => (int)$pdo->lastInsertId()
  ]);
} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error", "error" => $e->getMessage()]);
}