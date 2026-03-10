<?php
require_once "../config/db.php";
require_once "../middleware/auth.php";

$user = authenticate(['admin','user','bns']);
$userId = $user->sub;

if (!isset($_FILES['photo'])) {
  http_response_code(400);
  echo json_encode(["message"=>"No file"]);
  exit;
}

$file = $_FILES['photo'];

$blob = file_get_contents($file['tmp_name']);
$type = $file['type'];

$sql = "
UPDATE tbl_users
SET profile_photo = ?, profile_photo_type = ?
WHERE users_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$blob,$type,$userId]);

echo json_encode(["message"=>"Photo updated"]);