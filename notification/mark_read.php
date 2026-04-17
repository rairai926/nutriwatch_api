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

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) $data = [];

  $notifType = trim((string)($data['notif_type'] ?? ''));
  $notifRefId = (int)($data['notif_ref_id'] ?? 0);

  if ($notifType === '' || $notifRefId <= 0) {
    out(422, ['message' => 'Invalid notification']);
  }

  $sql = "
    INSERT IGNORE INTO tbl_notification_reads (users_id, notif_type, notif_ref_id)
    VALUES (?, ?, ?)
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$userId, $notifType, $notifRefId]);

  out(200, ['message' => 'Notification marked as read']);
} catch (Throwable $e) {
  out(500, [
    'message' => 'Server error',
    'error' => $e->getMessage()
  ]);
}