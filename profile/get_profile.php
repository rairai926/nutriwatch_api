<?php
require_once "../config/db.php";
require_once "../middleware/auth.php";

$user = authenticate(['admin','user','bns']);
$userId = $user->sub;

$sql = "
SELECT
users_id,
firstname,
middlename,
lastname,
role,
barangay_id,
profile_photo,
profile_photo_type
FROM tbl_users
WHERE users_id = ?
LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(404);
  echo json_encode(["message"=>"User not found"]);
  exit;
}

if (!empty($row['profile_photo'])) {
  $row['profile_photo'] = 'data:'.$row['profile_photo_type'].';base64,'.base64_encode($row['profile_photo']);
}

echo json_encode($row);