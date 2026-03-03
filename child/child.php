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
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../config/db.php";

// OPTIONAL auth (enable if you have middleware/auth.php)
// require_once __DIR__ . "/../middleware/auth.php";
// $user = authenticate(['admin', 'user']);

$barangayId = isset($_GET["barangay_id"]) ? (int)$_GET["barangay_id"] : 0;
if ($barangayId <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "barangay_id is required"]);
  exit;
}

try {
  // Adjust column names if yours differ
  $sql = "
    SELECT
      c.child_seq,
      c.child_id,
      c.child_name,
      c.sex,
      c.birthdate,
      c.guardian_name,
      c.purok,
      b.barangay_name
    FROM tbl_child_info c
    JOIN tbl_barangay b ON b.barangay_id = c.barangay_id
    WHERE c.barangay_id = ?
    ORDER BY c.child_name ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$barangayId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}