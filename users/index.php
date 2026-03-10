<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin', 'user']);

$role = $authUser->role ?? 'user';
$userId = (int)($authUser->sub ?? 0);

if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(["message" => "Unauthorized"]);
  exit;
}

function mapPhotoRow($row) {
  if (!empty($row['profile_photo']) && !empty($row['profile_photo_type'])) {
    $row['profile_photo'] = 'data:' . $row['profile_photo_type'] . ';base64,' . base64_encode($row['profile_photo']);
  } else {
    $row['profile_photo'] = null;
  }
  unset($row['profile_photo_type']);
  return $row;
}

if ($role === 'admin') {
  $stmt = $pdo->query("
    SELECT
      users_id,
      lastname,
      firstname,
      middlename,
      email,
      username,
      role,
      barangay_id,
      status,
      profile_photo,
      profile_photo_type,
      created_at
    FROM tbl_users
    ORDER BY users_id DESC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $rows = array_map('mapPhotoRow', $rows);
  echo json_encode($rows);
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    users_id,
    lastname,
    firstname,
    middlename,
    email,
    username,
    role,
    barangay_id,
    status,
    profile_photo,
    profile_photo_type,
    created_at
  FROM tbl_users
  WHERE users_id = ?
  LIMIT 1
");
$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($user ? mapPhotoRow($user) : []);