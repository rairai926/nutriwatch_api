<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin']);
$userId = (int)($authUser->sub ?? 0);

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
  INSERT INTO tbl_announcement
    (user_id, announcement_title, message, date_start, time_start, date_end, time_end, date_posted, venue, is_global, barangay_id, active, send_to)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
  $userId,
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
  $sendTo
]);

echo json_encode(["message" => "Announcement created successfully"]);