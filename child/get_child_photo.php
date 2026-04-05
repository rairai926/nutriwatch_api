<?php
require_once __DIR__ . "/../config/db.php";

$childSeq = (int)($_GET['child_seq'] ?? 0);

if ($childSeq <= 0) {
  http_response_code(400);
  exit("Invalid child_seq");
}

$stmt = $pdo->prepare("
  SELECT child_photo, child_photo_type
  FROM tbl_child_info
  WHERE child_seq = ?
  LIMIT 1
");
$stmt->execute([$childSeq]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['child_photo'])) {
  http_response_code(404);
  exit("No photo found");
}

$contentType = $row['child_photo_type'] ?: 'image/jpeg';

header("Content-Type: " . $contentType);
echo $row['child_photo'];
exit;