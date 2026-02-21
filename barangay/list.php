<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../config/db.php"; // <-- this gives you $pdo

try {
  $stmt = $pdo->query("SELECT barangay_id, barangay_name FROM tbl_barangay ORDER BY barangay_name ASC");
  $rows = $stmt->fetchAll();

  echo json_encode([
    "success" => true,
    "data" => $rows
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "Failed to fetch barangays",
    "error" => $e->getMessage()
  ]);
}