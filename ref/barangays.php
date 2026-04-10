<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

// Only logged-in users should access this.
// If you want ONLY admin, change allow list to ['admin'].
$authUser = authenticate(['admin', 'user', 'bns']);
$role = strtolower($authUser->role ?? 'user');
$userId = (int)($authUser->sub ?? 0);

try {
  // Admin → all barangays
  if ($role === 'admin') {
    $st = $pdo->query("
      SELECT barangay_id, barangay_code, barangay_name
      FROM tbl_barangay
      ORDER BY barangay_name ASC
    ");

    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
  }

  // User/BNS → only their barangay (restricted)
  $st = $pdo->prepare("
    SELECT b.barangay_id, b.barangay_code, b.barangay_name
    FROM tbl_users u
    JOIN tbl_barangay b ON b.barangay_id = u.barangay_id
    WHERE u.users_id = ?
    LIMIT 1
  ");
  $st->execute([$userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo json_encode(["message" => "No barangay assigned"]);
    exit;
  }

  echo json_encode([$row]); // return array for same frontend handling
  exit;
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(["message" => "Server error"]);
  exit;
}