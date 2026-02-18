<?php
require_once '../config/db.php';
require_once '../middleware/auth.php';

if ($authUser->role !== 'admin') {
  http_response_code(403);
  echo json_encode(["message" => "Forbidden"]);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data['users_id'] ?? 0);

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "Missing users_id"]);
  exit;
}

$pdo->prepare("DELETE FROM tbl_users WHERE users_id=?")->execute([$id]);
echo json_encode(["message" => "User deleted successfully"]);
