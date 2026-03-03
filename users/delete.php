<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin']); // ✅ admin only

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data['users_id'] ?? 0);

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "Missing users_id"]);
  exit;
}

// optional: prevent admin deleting self
if ((int)$authUser->sub === $id) {
  http_response_code(400);
  echo json_encode(["message" => "You cannot delete your own account"]);
  exit;
}

$pdo->prepare("DELETE FROM tbl_users WHERE users_id=?")->execute([$id]);
echo json_encode(["message" => "User deleted successfully"]);