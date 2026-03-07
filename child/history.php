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
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);
    if ($userBarangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $checkSql = "SELECT child_seq FROM tbl_child_info WHERE child_seq = ?";
  $checkParams = [$childSeq];

  if ($role !== 'admin') {
    $checkSql .= " AND barangay_id = ?";
    $checkParams[] = $userBarangayId;
  }

  $checkSql .= " LIMIT 1";
  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);

  if (!$st->fetchColumn()) {
    out(404, ["message" => "Child not found"]);
  }

  $sql = "
    SELECT
      m.measure_id,
      m.child_seq,
      m.date_measured,
      m.weight,
      m.height,
      m.muac,
      m.age_months,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.bilateral_pitting,
      m.user_id
    FROM tbl_measurement m
    WHERE m.child_seq = ?
    ORDER BY m.date_measured DESC, m.measure_id DESC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$childSeq]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  out(200, ["rows" => $rows]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}