<?php


require_once __DIR__ . "/../config/db.php";


require_once __DIR__ . "/../middleware/auth.php";
$user = authenticate(['admin', 'user']);

$barangayId = isset($_GET["barangay_id"]) ? (int)$_GET["barangay_id"] : 0;
if ($barangayId <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "barangay_id is required"]);
  exit;
}

try {
  $sql = "
    SELECT
      child_seq,
      purok,
      c_firstname,
      c_middlename,
      c_lastname,
      g_firstname,
      g_middlename,
      g_lastname,
      sex,
      date_birth,
      disability,
      ip_group
    FROM tbl_child_info
    WHERE barangay_id = ?
    ORDER BY c_lastname ASC, c_firstname ASC
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