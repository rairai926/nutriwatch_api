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

  $actorUserId = (int)($authUser->sub ?? 0);
  $actorRole = strtolower((string)($authUser->role ?? 'user'));

  $id = (int)($_POST['users_id'] ?? 0);
  if ($id <= 0) {
    audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', null, 'Missing users_id');
    out(400, ["ok" => false, "message" => "Missing users_id"]);
  }

  $isAdmin = ($actorRole === 'admin');
  $isSelf  = ($actorUserId === $id);

  if (!$isAdmin && !$isSelf) {
    audit_log($pdo, $actorUserId, 'USER_UPDATE_DENIED', 'tbl_users', (string)$id, 'Forbidden');
    out(403, ["ok" => false, "message" => "Forbidden"]);
  }

  $st = $pdo->prepare("
    SELECT users_id, lastname, firstname, middlename, email, username, role, barangay_id, status
    FROM tbl_users
    WHERE users_id = ?
    LIMIT 1
  ");
  $st->execute([$id]);
  $existing = $st->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', (string)$id, 'User not found');
    out(404, ["ok" => false, "message" => "User not found"]);
  }

  $lastname   = trim($_POST['lastname'] ?? '');
  $firstname  = trim($_POST['firstname'] ?? '');
  $middlename = trim($_POST['middlename'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $username   = trim($_POST['username'] ?? '');
  $status     = strtolower(trim($_POST['status'] ?? ''));
  $barangayId = (int)($_POST['barangay_id'] ?? 0);

  $role = $isAdmin ? strtolower(trim($_POST['role'] ?? '')) : null;

  $newPassword = trim($_POST['password'] ?? '');
  $hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', (string)$id, 'Invalid email');
    out(422, ["ok" => false, "message" => "Invalid email address"]);
  }

  if ($isAdmin && $role !== '') {
    $allowedRoles = ['admin', 'user', 'bns'];
    if (!in_array($role, $allowedRoles, true)) {
      audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', (string)$id, "Invalid role: {$role}");
      out(422, ["ok" => false, "message" => "Invalid role"]);
    }
  }

  if ($isAdmin && $status !== '') {
    $allowedStatuses = ['active', 'inactive'];
    if (!in_array($status, $allowedStatuses, true)) {
      audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', (string)$id, "Invalid status: {$status}");
      out(422, ["ok" => false, "message" => "Invalid status"]);
    }
  }

  if ($isAdmin && $barangayId > 0) {
    $checkBarangay = $pdo->prepare("SELECT COUNT(*) FROM tbl_barangay WHERE barangay_id = ?");
    $checkBarangay->execute([$barangayId]);
    if (!(int)$checkBarangay->fetchColumn()) {
      audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', (string)$id, "Invalid barangay_id: {$barangayId}");
      out(422, ["ok" => false, "message" => "Invalid barangay"]);
    }
  }

  if ($email !== '') {
    $dup = $pdo->prepare("SELECT users_id FROM tbl_users WHERE LOWER(email) = LOWER(?) AND users_id <> ? LIMIT 1");
    $dup->execute([$email, $id]);
    if ($dup->fetchColumn()) {
      audit_log($pdo, $actorUserId, 'USER_UPDATE_DUPLICATE', 'tbl_users', (string)$id, 'Duplicate email');
      out(409, ["ok" => false, "message" => "Email already exists"]);
    }
  }

  if ($username !== '') {
    $dup = $pdo->prepare("SELECT users_id FROM tbl_users WHERE LOWER(username) = LOWER(?) AND users_id <> ? LIMIT 1");
    $dup->execute([$username, $id]);
    if ($dup->fetchColumn()) {
      audit_log($pdo, $actorUserId, 'USER_UPDATE_DUPLICATE', 'tbl_users', (string)$id, 'Duplicate username');
      out(409, ["ok" => false, "message" => "Username already exists"]);
    }
  }

  if ($newPassword !== '' && strlen($newPassword) < 8) {
    audit_log($pdo, $actorUserId, 'USER_UPDATE_FAILED', 'tbl_users', (string)$id, 'Password too short');
    out(422, ["ok" => false, "message" => "Password must be at least 8 characters"]);
  }

  $fields = [];
  $params = [];
  $changes = [];

  if ($lastname !== '')   { $fields[] = "lastname=?";   $params[] = $lastname;   if (($existing['lastname'] ?? '') !== $lastname) $changes[] = "lastname"; }
  if ($firstname !== '')  { $fields[] = "firstname=?";  $params[] = $firstname;  if (($existing['firstname'] ?? '') !== $firstname) $changes[] = "firstname"; }
  if ($middlename !== '') { $fields[] = "middlename=?"; $params[] = $middlename; if (($existing['middlename'] ?? '') !== $middlename) $changes[] = "middlename"; }
  if ($email !== '')      { $fields[] = "email=?";      $params[] = $email;      if (($existing['email'] ?? '') !== $email) $changes[] = "email"; }
  if ($username !== '')   { $fields[] = "username=?";   $params[] = $username;   if (($existing['username'] ?? '') !== $username) $changes[] = "username"; }

  if ($isAdmin && $role !== '') {
    $fields[] = "role=?";
    $params[] = $role;
    if (($existing['role'] ?? '') !== $role) $changes[] = "role";
  }

  if ($isAdmin && $barangayId > 0) {
    $fields[] = "barangay_id=?";
    $params[] = $barangayId;
    if ((int)($existing['barangay_id'] ?? 0) !== $barangayId) $changes[] = "barangay_id";
  }

  if ($isAdmin && $status !== '') {
    $fields[] = "status=?";
    $params[] = $status;
    if (($existing['status'] ?? '') !== $status) $changes[] = "status";
  }

  if ($hash) {
    $fields[] = "password=?";
    $params[] = $hash;
    $changes[] = "password";
  }

  if (empty($fields)) {
    out(400, ["ok" => false, "message" => "No fields to update"]);
  }

  $params[] = $id;

  $sql = "UPDATE tbl_users SET " . implode(", ", $fields) . " WHERE users_id=?";
  $pdo->prepare($sql)->execute($params);

  $changedText = !empty($changes) ? implode(', ', $changes) : 'no visible field changes';

  audit_log(
    $pdo,
    $actorUserId,
    'USER_UPDATED',
    'tbl_users',
    (string)$id,
    "Updated user {$existing['username']} (users_id={$id}); changed: {$changedText}"
  );

  echo json_encode(["ok" => true, "message" => "User updated successfully"]);
} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error", "error" => $e->getMessage()]);
}