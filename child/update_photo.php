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
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $childSeq = (int)($_POST['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    out(422, ["message" => "Invalid child_seq"]);
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      out(403, ["message" => "No barangay assigned"]);
    }
  }

  $checkSql = "SELECT child_seq FROM tbl_child_info WHERE child_seq = ?";
  $checkParams = [$childSeq];

  if ($role !== 'admin') {
    $checkSql .= " AND barangay_id = ?";
    $checkParams[] = $userBarangayId;
  }

  $checkSql .= " LIMIT 1";
  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);

  if (!$st->fetchColumn()) {
    out(404, ["message" => "Child not found"]);
  }

  if (empty($_FILES['photo']['name']) || !isset($_FILES['photo']['tmp_name'])) {
    out(422, ["message" => "No photo uploaded"]);
  }

  if (!is_uploaded_file($_FILES['photo']['tmp_name'])) {
    out(422, ["message" => "Invalid upload"]);
  }

  if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    out(422, ["message" => "Upload failed with error code " . (int)$_FILES['photo']['error']]);
  }

  $allowedMime = [
    'image/jpeg',
    'image/png',
    'image/webp'
  ];

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mimeType = finfo_file($finfo, $_FILES['photo']['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mimeType, $allowedMime, true)) {
    out(422, ["message" => "Invalid image type. Only JPG, PNG, and WEBP are allowed."]);
  }

  $fileSize = (int)($_FILES['photo']['size'] ?? 0);
  $maxSize = 5 * 1024 * 1024;

  if ($fileSize <= 0 || $fileSize > $maxSize) {
    out(422, ["message" => "Image must be greater than 0 bytes and not more than 5MB."]);
  }

  $imageData = file_get_contents($_FILES['photo']['tmp_name']);
  if ($imageData === false) {
    out(500, ["message" => "Failed to read uploaded image"]);
  }

  $sql = "UPDATE tbl_child_info
          SET child_photo = ?, child_photo_type = ?
          WHERE child_seq = ?";
  $st = $pdo->prepare($sql);
  $st->bindParam(1, $imageData, PDO::PARAM_LOB);
  $st->bindParam(2, $mimeType, PDO::PARAM_STR);
  $st->bindParam(3, $childSeq, PDO::PARAM_INT);
  $st->execute();

  out(200, [
    "message" => "Photo updated successfully",
    "child_seq" => $childSeq,
    "child_photo_type" => $mimeType
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}