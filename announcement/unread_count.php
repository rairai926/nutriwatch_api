<?php


require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

$authUser = authenticate(['admin', 'user']);
$role = $authUser->role ?? 'user';
$userId = (int)($authUser->sub ?? 0);

// BNS scope
$barangayId = 0;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["message" => "No barangay assigned"]);
    exit;
  }
}

if ($role === 'admin') {
  $sql = "
    SELECT COUNT(*)
    FROM tbl_announcement a
    WHERE a.active = 1
      AND a.date_start <= CURDATE()
      AND a.date_end >= CURDATE()
      AND NOT EXISTS (
        SELECT 1 FROM tbl_announcement_reads r
        WHERE r.announcement_id=a.announcement_id AND r.users_id=?
      )
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId]);
  echo json_encode(["unread" => (int)$stmt->fetchColumn()]);
  exit;
}

$sql = "
  SELECT COUNT(*)
  FROM tbl_announcement a
  WHERE a.active = 1
    AND a.date_start <= CURDATE()
    AND a.date_end >= CURDATE()
    AND (
      a.is_global = 1
      OR (a.is_global = 0 AND a.barangay_id = ?)
    )
    AND NOT EXISTS (
      SELECT 1 FROM tbl_announcement_reads r
      WHERE r.announcement_id=a.announcement_id AND r.users_id=?
    )
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$barangayId, $userId]);
echo json_encode(["unread" => (int)$stmt->fetchColumn()]);