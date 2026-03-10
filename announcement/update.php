<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

authenticate(['admin']);

$id = (int)($_POST['announcement_id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "Missing announcement_id"]);
  exit;
}

$title = trim($_POST['announcement_title'] ?? '');
$message = trim($_POST['message'] ?? '');
$dateStart = trim($_POST['date_start'] ?? '');
$timeStart = trim($_POST['time_start'] ?? '');
$dateEnd = trim($_POST['date_end'] ?? '');
$timeEnd = trim($_POST['time_end'] ?? '');
$datePosted = trim($_POST['date_posted'] ?? date('Y-m-d'));
$venue = trim($_POST['venue'] ?? '');
$isGlobal = (int)($_POST['is_global'] ?? 1);
$barangayId = ($_POST['barangay_id'] ?? '') !== '' ? (int)$_POST['barangay_id'] : null;
$active = (int)($_POST['active'] ?? 1);
$sendTo = trim($_POST['send_to'] ?? 'all');

if ($title === '' || $message === '' || $dateStart === '' || $timeStart === '' || $dateEnd === '' || $timeEnd === '') {
  http_response_code(400);
  echo json_encode(["message" => "Missing required fields"]);
  exit;
}

if ($isGlobal !== 1 && !$barangayId) {
  http_response_code(400);
  echo json_encode(["message" => "Barangay is required for exclusive announcement"]);
  exit;
}

$stmt = $pdo->prepare("
  UPDATE tbl_announcement
  SET
    announcement_title = ?,
    message = ?,
    date_start = ?,
    time_start = ?,
    date_end = ?,
    time_end = ?,
    date_posted = ?,
    venue = ?,
    is_global = ?,
    barangay_id = ?,
    active = ?,
    send_to = ?
  WHERE announcement_id = ?
");
$stmt->execute([
  $title,
  $message,
  $dateStart,
  $timeStart,
  $dateEnd,
  $timeEnd,
  $datePosted,
  $venue,
  $isGlobal,
  $isGlobal === 1 ? null : $barangayId,
  $active,
  $sendTo,
  $id
]);

echo json_encode(["message" => "Announcement updated successfully"]);