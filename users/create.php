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
  $authUser = authenticate(['admin']);
  $adminUserId = (int)($authUser->sub ?? 0);

  $lastname   = trim($_POST['lastname'] ?? '');
  $firstname  = trim($_POST['firstname'] ?? '');
  $middlename = trim($_POST['middlename'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $username   = trim($_POST['username'] ?? '');
  $password   = trim($_POST['password'] ?? '');
  $role       = strtolower(trim($_POST['role'] ?? 'user'));
  $barangayId = (int)($_POST['barangay_id'] ?? 0);
  $status     = strtolower(trim($_POST['status'] ?? 'active'));

  if ($lastname === '' || $firstname === '' || $email === '' || $username === '' || $password === '') {
    audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, 'Missing required fields');
    out(400, ["ok" => false, "message" => "Missing required fields"]);
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, "Invalid email: {$email}");
    out(422, ["ok" => false, "message" => "Invalid email address"]);
  }

  $allowedRoles = ['admin', 'user', 'bns'];
  if (!in_array($role, $allowedRoles, true)) {
    audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, "Invalid role: {$role}");
    out(422, ["ok" => false, "message" => "Invalid role"]);
  }

  $allowedStatuses = ['active', 'inactive'];
  if (!in_array($status, $allowedStatuses, true)) {
    audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, "Invalid status: {$status}");
    out(422, ["ok" => false, "message" => "Invalid status"]);
  }

  if (strlen($password) < 8) {
    audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, 'Password too short');
    out(422, ["ok" => false, "message" => "Password must be at least 8 characters"]);
  }

  if ($role !== 'admin' && $barangayId <= 0) {
    audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, 'barangay_id required for non-admin');
    out(422, ["ok" => false, "message" => "Barangay is required for non-admin users"]);
  }

  if ($barangayId > 0) {
    $checkBarangay = $pdo->prepare("SELECT COUNT(*) FROM tbl_barangay WHERE barangay_id = ?");
    $checkBarangay->execute([$barangayId]);
    if (!(int)$checkBarangay->fetchColumn()) {
      audit_log($pdo, $adminUserId, 'USER_CREATE_FAILED', 'tbl_users', null, "Invalid barangay_id: {$barangayId}");
      out(422, ["ok" => false, "message" => "Invalid barangay"]);
    }
  }

  $dup = $pdo->prepare("
    SELECT users_id, email, username
    FROM tbl_users
    WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)
    LIMIT 1
  ");
  $dup->execute([$email, $username]);
  $existing = $dup->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $reason = strtolower($existing['email'] ?? '') === strtolower($email)
      ? "Email already exists"
      : "Username already exists";

    audit_log($pdo, $adminUserId, 'USER_CREATE_DUPLICATE', 'tbl_users', (string)($existing['users_id'] ?? ''), $reason);
    out(409, ["ok" => false, "message" => $reason]);
  }

  $hash = password_hash($password, PASSWORD_ARGON2ID);

  $stmt = $pdo->prepare("
    INSERT INTO tbl_users
      (
        lastname,
        firstname,
        middlename,
        email,
        username,
        password,
        must_change_password,
        password_changed_at,
        role,
        barangay_id,
        status,
        created_at
      )
    VALUES
      (?, ?, ?, ?, ?, ?, 1, NULL, ?, ?, ?, NOW())
  ");

  $stmt->execute([
    $lastname,
    $firstname,
    $middlename !== '' ? $middlename : null,
    $email,
    $username,
    $hash,
    $role,
    $barangayId > 0 ? $barangayId : null,
    $status
  ]);

  $newUserId = (int)$pdo->lastInsertId();

  audit_log(
    $pdo,
    $adminUserId,
    'USER_CREATED',
    'tbl_users',
    (string)$newUserId,
    "Created user {$firstname} {$lastname} ({$username}, {$email}) with role={$role}" . ($barangayId > 0 ? ", barangay_id={$barangayId}" : '')
  );

  echo json_encode([
    "ok" => true,
    "message" => "User created successfully",
    "users_id" => $newUserId
  ]);
} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error", "error" => $e->getMessage()]);
}