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

  $where = ["ci.child_seq = ?"];
  $params = [$childSeq];

  if ($role !== 'admin') {
    $where[] = "ci.barangay_id = ?";
    $params[] = $userBarangayId;
  }

  $whereSql = "WHERE " . implode(" AND ", $where);

  $sql = "
    SELECT
      ci.child_seq,
      ci.province_id,
      ci.city_id,
      ci.barangay_id,
      ci.purok,
      ci.g_lastname,
      ci.g_firstname,
      ci.g_middlename,
      ci.c_lastname,
      ci.c_firstname,
      ci.c_middlename,
      ci.ip_group,
      ci.sex,
      ci.date_birth,
      ci.disability,
      ci.child_photo,
      ci.user_id,
      b.barangay_name
    FROM tbl_child_info ci
    LEFT JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id
    $whereSql
    LIMIT 1
  ";

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
      m.user_id,
      m.date_measured,
      m.weight,
      m.height,
      m.muac,
      m.age_months,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.bilateral_pitting
    FROM tbl_measurement m
    WHERE m.child_seq = ?
    ORDER BY m.date_measured DESC, m.measure_id DESC
    LIMIT 1
  ";

  $st = $pdo->prepare($latestSql);
  $st->execute([$childSeq]);
  $latest = $st->fetch(PDO::FETCH_ASSOC);

  // if no measurement yet, return null instead of false
  if (!$latest) {
    $latest = null;
  } else {
    if (!empty($child['date_birth']) && !empty($latest['date_measured'])) {
      $birth = new DateTime($child['date_birth']);
      $measured = new DateTime($latest['date_measured']);
      $diff = $birth->diff($measured);
      $latest['age_days'] = (int)$diff->days;
    } else {
      $latest['age_days'] = null;
    }
  }

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