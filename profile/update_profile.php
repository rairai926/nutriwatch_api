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

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];

  $firstname = trim((string)($data['firstname'] ?? ''));
  $middlename = trim((string)($data['middlename'] ?? ''));
  $lastname = trim((string)($data['lastname'] ?? ''));
  $newPassword = trim((string)($data['password'] ?? ''));
  $email = trim((string)($data['email'] ?? ''));

  if ($firstname === '' || $lastname === '') {
    audit_log($pdo, $userId, 'PROFILE_UPDATE_FAILED', 'tbl_users', (string)$userId, 'First name or last name missing');
    out(422, ["message" => "First name and last name are required"]);
  }

  $st = $pdo->prepare("
    SELECT users_id, firstname, middlename, lastname, email, username
    FROM tbl_users
    WHERE users_id = ?
    LIMIT 1
  ");
  $st->execute([$userId]);
  $existing = $st->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    audit_log($pdo, $userId, 'PROFILE_UPDATE_FAILED', 'tbl_users', (string)$userId, 'User not found');
    out(404, ["message" => "User not found"]);
  }

  if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      audit_log($pdo, $userId, 'PROFILE_UPDATE_FAILED', 'tbl_users', (string)$userId, 'Invalid email format');
      out(422, ["message" => "Invalid email address"]);
    }

    $dup = $pdo->prepare("SELECT users_id FROM tbl_users WHERE LOWER(email) = LOWER(?) AND users_id <> ? LIMIT 1");
    $dup->execute([$email, $userId]);
    if ($dup->fetchColumn()) {
      audit_log($pdo, $userId, 'PROFILE_UPDATE_DUPLICATE', 'tbl_users', (string)$userId, 'Duplicate email');
      out(409, ["message" => "Email already exists"]);
    }
  }

  if ($newPassword !== '' && strlen($newPassword) < 8) {
    audit_log($pdo, $userId, 'PROFILE_UPDATE_FAILED', 'tbl_users', (string)$userId, 'Password too short');
    out(422, ["message" => "Password must be at least 8 characters"]);
  }

  $fields = [
    "firstname = :firstname",
    "middlename = :middlename",
    "lastname = :lastname"
  ];

  $params = [
    ':firstname' => $firstname,
    ':middlename' => $middlename !== '' ? $middlename : null,
    ':lastname' => $lastname,
    ':users_id' => $userId
  ];

  $changes = [];

  if (($existing['firstname'] ?? '') !== $firstname) $changes[] = 'firstname';
  if (($existing['middlename'] ?? '') !== $middlename) $changes[] = 'middlename';
  if (($existing['lastname'] ?? '') !== $lastname) $changes[] = 'lastname';

  if ($email !== '') {
    $fields[] = "email = :email";
    $params[':email'] = $email;
    if (($existing['email'] ?? '') !== $email) $changes[] = 'email';
  }

  if ($newPassword !== '') {
    $passwordHash = password_hash($newPassword, PASSWORD_ARGON2ID);
    $fields[] = "password = :password";
    $fields[] = "must_change_password = 0";
    $fields[] = "password_changed_at = NOW()";
    $params[':password'] = $passwordHash;
    $changes[] = 'password';
  }

  $sql = "
    UPDATE tbl_users
    SET " . implode(", ", $fields) . "
    WHERE users_id = :users_id
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $changedText = !empty($changes) ? implode(', ', $changes) : 'no visible field changes';

  audit_log(
    $pdo,
    $userId,
    'PROFILE_UPDATED',
    'tbl_users',
    (string)$userId,
    "Updated own profile; changed: {$changedText}"
  );

  out(200, ["message" => "Profile updated successfully"]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}