<?php
ob_start();
session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $childSeq = (int)($_GET['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    http_response_code(400);
    exit("Invalid child_seq");
  }

  $userBarangayId = 0;
  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $st->execute([$userId]);
    $userBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      http_response_code(403);
      exit("No barangay assigned");
    }
  }

  $sql = "
    SELECT child_photo, child_photo_type
    FROM tbl_child_info
    WHERE child_seq = ?
  ";
  $params = [$childSeq];

  if ($role !== 'admin') {
    $sql .= " AND barangay_id = ?";
    $params[] = $userBarangayId;
  }

  $sql .= " LIMIT 1";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row['child_photo'])) {
    http_response_code(404);
    exit("No photo found");
  }

  $contentType = trim((string)($row['child_photo_type'] ?? ''));
  if ($contentType === '') {
    $contentType = 'image/jpeg';
  }

  header("Content-Type: " . $contentType);
  header("Cache-Control: private, max-age=86400");
  echo $row['child_photo'];
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  exit("Server error");
}