<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $userId = (int)($authUser->sub ?? 0);

  $sql = "
    SELECT
      u.users_id,
      u.firstname,
      u.middlename,
      u.lastname,
      u.email,
      u.username,
      u.role,
      u.barangay_id,
      b.barangay_name,
      u.profile_photo,
      u.profile_photo_type
    FROM tbl_users u
    LEFT JOIN tbl_barangay b
      ON b.barangay_id = u.barangay_id
    WHERE u.users_id = ?
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    out(404, ["message" => "User not found"]);
  }

  if (!empty($row['profile_photo']) && !empty($row['profile_photo_type'])) {
    $row['profile_photo'] = 'data:' . $row['profile_photo_type'] . ';base64,' . base64_encode($row['profile_photo']);
  } else {
    $row['profile_photo'] = null;
  }

  unset($row['profile_photo_type']);

  out(200, $row);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}