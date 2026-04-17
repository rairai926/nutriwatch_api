<?php


require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function audit_log(PDO $pdo, ?int $userId, string $action, ?string $targetTable, ?string $targetId, ?string $description): void {
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
      $ip = trim(explode(',', $ip)[0]);
    }

    $st = $pdo->prepare("
      INSERT INTO tbl_audit_logs (user_id, action, target_table, target_id, description, ip_address)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $st->execute([$userId, $action, $targetTable, $targetId, $description, $ip !== '' ? $ip : null]);
  } catch (Throwable $e) {
    error_log("Audit log failed: " . $e->getMessage());
  }
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $userId = (int)($authUser->sub ?? 0);

  if (!isset($_FILES['photo'])) {
    audit_log($pdo, $userId, 'PROFILE_PHOTO_UPLOAD_FAILED', 'tbl_users', (string)$userId, 'No photo uploaded');
    out(422, ["message" => "No photo uploaded"]);
  }

  $file = $_FILES['photo'];

  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    audit_log($pdo, $userId, 'PROFILE_PHOTO_UPLOAD_FAILED', 'tbl_users', (string)$userId, 'Upload error code: ' . ($file['error'] ?? 'unknown'));
    out(400, ["message" => "Upload failed"]);
  }

  $maxSize = 2 * 1024 * 1024; // 2MB
  if (($file['size'] ?? 0) > $maxSize) {
    audit_log($pdo, $userId, 'PROFILE_PHOTO_UPLOAD_FAILED', 'tbl_users', (string)$userId, 'Photo exceeded 2MB');
    out(422, ["message" => "Photo must not exceed 2MB"]);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($mime, $allowedTypes, true)) {
    audit_log($pdo, $userId, 'PROFILE_PHOTO_UPLOAD_FAILED', 'tbl_users', (string)$userId, "Invalid MIME type: {$mime}");
    out(422, ["message" => "Only JPG, PNG, and WEBP are allowed"]);
  }

  $imageInfo = @getimagesize($file['tmp_name']);
  if ($imageInfo === false) {
    audit_log($pdo, $userId, 'PROFILE_PHOTO_UPLOAD_FAILED', 'tbl_users', (string)$userId, 'Uploaded file is not a valid image');
    out(422, ["message" => "Uploaded file is not a valid image"]);
  }

  $blob = file_get_contents($file['tmp_name']);
  if ($blob === false) {
    audit_log($pdo, $userId, 'PROFILE_PHOTO_UPLOAD_FAILED', 'tbl_users', (string)$userId, 'Failed to read uploaded file');
    out(500, ["message" => "Failed to read uploaded file"]);
  }

  $sql = "
    UPDATE tbl_users
    SET
      profile_photo = :profile_photo,
      profile_photo_type = :profile_photo_type
    WHERE users_id = :users_id
  ";

  $st = $pdo->prepare($sql);
  $st->bindParam(':profile_photo', $blob, PDO::PARAM_LOB);
  $st->bindValue(':profile_photo_type', $mime);
  $st->bindValue(':users_id', $userId, PDO::PARAM_INT);
  $st->execute();

  audit_log(
    $pdo,
    $userId,
    'PROFILE_PHOTO_UPDATED',
    'tbl_users',
    (string)$userId,
    "Updated profile photo; mime={$mime}, size=" . (int)($file['size'] ?? 0)
  );

  out(200, ["message" => "Profile photo updated successfully"]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}