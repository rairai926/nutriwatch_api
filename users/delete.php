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

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) $data = [];

  $id = (int)($data['users_id'] ?? 0);

  if ($id <= 0) {
    audit_log($pdo, $adminUserId, 'USER_DELETE_FAILED', 'tbl_users', null, 'Missing users_id');
    out(400, ["ok" => false, "message" => "Missing users_id"]);
  }

  if ($adminUserId === $id) {
    audit_log($pdo, $adminUserId, 'USER_DELETE_DENIED', 'tbl_users', (string)$id, 'Attempted self-delete');
    out(400, ["ok" => false, "message" => "You cannot delete your own account"]);
  }

  $st = $pdo->prepare("
    SELECT users_id, firstname, middlename, lastname, username, email, role
    FROM tbl_users
    WHERE users_id = ?
    LIMIT 1
  ");
  $st->execute([$id]);
  $target = $st->fetch(PDO::FETCH_ASSOC);

  if (!$target) {
    audit_log($pdo, $adminUserId, 'USER_DELETE_FAILED', 'tbl_users', (string)$id, 'User not found');
    out(404, ["ok" => false, "message" => "User not found"]);
  }

  $pdo->prepare("DELETE FROM tbl_users WHERE users_id = ?")->execute([$id]);

  $fullName = trim(implode(' ', array_filter([
    $target['firstname'] ?? '',
    $target['middlename'] ?? '',
    $target['lastname'] ?? ''
  ])));

  audit_log(
    $pdo,
    $adminUserId,
    'USER_DELETED',
    'tbl_users',
    (string)$id,
    "Deleted user {$fullName} ({$target['username']}, {$target['email']}) role={$target['role']}"
  );

  echo json_encode(["ok" => true, "message" => "User deleted successfully"]);
} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error", "error" => $e->getMessage()]);
}