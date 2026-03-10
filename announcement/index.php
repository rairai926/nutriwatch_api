<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

authenticate(['admin']);

$stmt = $pdo->query("
  SELECT
    a.announcement_id,
    a.user_id,
    a.announcement_title,
    a.message,
    a.date_start,
    a.time_start,
    a.date_end,
    a.time_end,
    a.date_posted,
    a.venue,
    a.is_global,
    a.barangay_id,
    a.active,
    a.send_to,
    b.barangay_name
  FROM tbl_announcement a
  LEFT JOIN tbl_barangay b ON b.barangay_id = a.barangay_id
  ORDER BY a.announcement_id DESC
");

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));