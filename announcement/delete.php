<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

authenticate(['admin']);

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data['announcement_id'] ?? 0);

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "Missing announcement_id"]);
  exit;
}

$stmt = $pdo->prepare("DELETE FROM tbl_announcement WHERE announcement_id = ?");
$stmt->execute([$id]);

echo json_encode(["message" => "Announcement deleted successfully"]);