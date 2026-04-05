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
header("Access-Control-Allow-Methods: GET, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
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

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $childSeq = (int)($_GET['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    out(422, ["message" => "Invalid child_seq"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $sql = "
    SELECT
      c.child_seq,
      c.barangay_id,
      c.c_firstname,
      c.c_middlename,
      c.c_lastname,
      c.g_firstname,
      c.g_middlename,
      c.g_lastname,
      c.purok,
      c.sex,
      c.date_birth,
      c.ip_group,
      c.disability,
      c.child_photo_type,
      CASE
        WHEN c.child_photo IS NOT NULL AND OCTET_LENGTH(c.child_photo) > 0 THEN 1
        ELSE 0
      END AS has_photo,
      b.barangay_name
    FROM tbl_child_info c
    LEFT JOIN tbl_barangay b ON b.barangay_id = c.barangay_id
    WHERE c.child_seq = ?
  ";

  $params = [$childSeq];

  if ($role !== 'admin') {
    $sql .= " AND c.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  $sql .= " LIMIT 1";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $child = $st->fetch(PDO::FETCH_ASSOC);

  if (!$child) {
    out(404, ["message" => "Child not found"]);
  }

  $latestSql = "
    SELECT
      m.measure_id,
      m.child_seq,
      m.date_measured,
      m.age_months,
      m.age_days,
      m.weight,
      m.height,
      m.muac,
      m.bilateral_pitting,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.encoded_by,
      m.encoded_by_role
    FROM tbl_child_measurements m
    WHERE m.child_seq = ?
    ORDER BY m.date_measured DESC, m.measure_id DESC
    LIMIT 1
  ";

  $st = $pdo->prepare($latestSql);
  $st->execute([$childSeq]);
  $latest = $st->fetch(PDO::FETCH_ASSOC) ?: null;

  $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  $photoUrl = null;

  if ((int)($child['has_photo'] ?? 0) === 1) {
    $photoUrl = $basePath . "/get_child_photo.php?child_seq=" . (int)$child['child_seq'];
  }

  $child['has_photo'] = (int)($child['has_photo'] ?? 0);
  $child['photo_url'] = $photoUrl;

  out(200, [
    "child" => $child,
    "latest_measurement" => $latest
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}