<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../config/db.php";

$barangay_id = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;
if ($barangay_id <= 0) {
  http_response_code(400);
  echo json_encode(["success" => false, "message" => "barangay_id is required"]);
  exit;
}

try {
  // Barangay name
  $stmt = $pdo->prepare("SELECT barangay_id, barangay_name FROM tbl_barangay WHERE barangay_id = ?");
  $stmt->execute([$barangay_id]);
  $barangay = $stmt->fetch();

  if (!$barangay) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Barangay not found"]);
    exit;
  }

  // Total children in barangay
  $stmt = $pdo->prepare("SELECT COUNT(*) AS total_children FROM tbl_child_info WHERE barangay_id = ?");
  $stmt->execute([$barangay_id]);
  $total_children = (int)($stmt->fetch()['total_children'] ?? 0);

  // Total measurements for children in barangay
  $stmt = $pdo->prepare("
    SELECT COUNT(m.measure_id) AS total_measurements
    FROM tbl_measurement m
    INNER JOIN tbl_child_info c ON c.child_seq = m.child_seq
    WHERE c.barangay_id = ?
  ");
  $stmt->execute([$barangay_id]);
  $total_measurements = (int)($stmt->fetch()['total_measurements'] ?? 0);

  // Last measured date
  $stmt = $pdo->prepare("
    SELECT MAX(m.date_measured) AS last_measured
    FROM tbl_measurement m
    INNER JOIN tbl_child_info c ON c.child_seq = m.child_seq
    WHERE c.barangay_id = ?
  ");
  $stmt->execute([$barangay_id]);
  $last_measured = $stmt->fetch()['last_measured'] ?? null;

  // Total users assigned in barangay
  $stmt = $pdo->prepare("SELECT COUNT(*) AS total_users FROM tbl_users WHERE barangay_id = ?");
  $stmt->execute([$barangay_id]);
  $total_users = (int)($stmt->fetch()['total_users'] ?? 0);

  echo json_encode([
    "success" => true,
    "data" => [
      "barangay_id" => $barangay["barangay_id"],
      "barangay_name" => $barangay["barangay_name"],
      "total_users" => $total_users,
      "total_children" => $total_children,
      "total_measurements" => $total_measurements,
      "last_measured" => $last_measured
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "Failed to load summary",
    "error" => $e->getMessage()
  ]);
}