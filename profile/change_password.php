<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

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

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $currentPassword = trim((string)($data['current_password'] ?? ''));
  $newPassword = trim((string)($data['new_password'] ?? ''));
  $confirmPassword = trim((string)($data['confirm_password'] ?? ''));

  if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    audit_log($pdo, $userId, 'PASSWORD_CHANGE_FAILED', 'tbl_users', (string)$userId, 'Missing required password fields');
    out(422, ["message" => "All password fields are required"]);
  }

  if (strlen($newPassword) < 8) {
    audit_log($pdo, $userId, 'PASSWORD_CHANGE_FAILED', 'tbl_users', (string)$userId, 'New password too short');
    out(422, ["message" => "New password must be at least 8 characters"]);
  }

  if ($newPassword !== $confirmPassword) {
    audit_log($pdo, $userId, 'PASSWORD_CHANGE_FAILED', 'tbl_users', (string)$userId, 'Password confirmation mismatch');
    out(422, ["message" => "New password and confirmation do not match"]);
  }

  if ($currentPassword === $newPassword) {
    audit_log($pdo, $userId, 'PASSWORD_CHANGE_FAILED', 'tbl_users', (string)$userId, 'New password same as current');
    out(422, ["message" => "New password must be different from current password"]);
  }

  $stmt = $pdo->prepare("
    SELECT users_id, password, username
    FROM tbl_users
    WHERE users_id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    audit_log($pdo, $userId, 'PASSWORD_CHANGE_FAILED', 'tbl_users', (string)$userId, 'User not found');
    out(404, ["message" => "User not found"]);
  }

  if (!password_verify($currentPassword, $user['password'])) {
    audit_log($pdo, $userId, 'PASSWORD_CHANGE_DENIED', 'tbl_users', (string)$userId, 'Current password incorrect');
    out(422, ["message" => "Current password is incorrect"]);
  }

  $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);

  $update = $pdo->prepare("
    UPDATE tbl_users
    SET
      password = ?,
      must_change_password = 0,
      password_changed_at = NOW(),
      status = 'active'
    WHERE users_id = ?
    LIMIT 1
  ");
  $update->execute([$newHash, $userId]);

  audit_log(
    $pdo,
    $userId,
    'PASSWORD_CHANGED',
    'tbl_users',
    (string)$userId,
    'Password changed successfully for username=' . ($user['username'] ?? '')
  );

  out(200, ["message" => "Password changed successfully"]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}