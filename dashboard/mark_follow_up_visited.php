<?php

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

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
    $st->execute([
      $userId,
      $action,
      $targetTable,
      $targetId,
      $description,
      $ip !== '' ? $ip : null
    ]);
  } catch (Throwable $e) {
    // Do not block main flow if audit logging fails
    error_log("Audit log failed: " . $e->getMessage());
  }
}

$user = authenticate(['admin', 'user']);
$userId = (int)($user->sub ?? 0);
$role = strtolower((string)($user->role ?? 'user'));

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) $input = [];

$childSeq = (int)($input["child_seq"] ?? 0);
$note = trim((string)($input["note"] ?? ""));

if ($childSeq <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "child_seq is required"]);
  exit;
}

// Lookup child for validation and logging
$childInfo = null;

if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    audit_log($pdo, $userId, 'FOLLOW_UP_VISIT_DENIED', 'tbl_follow_up_visits', (string)$childSeq, 'No barangay assigned');
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "No barangay assigned"]);
    exit;
  }

  $check = $pdo->prepare("
    SELECT ci.child_seq, ci.barangay_id, ci.c_firstname, ci.c_middlename, ci.c_lastname
    FROM tbl_child_info ci
    WHERE ci.child_seq = ? AND ci.barangay_id = ?
    LIMIT 1
  ");
  $check->execute([$childSeq, $barangayId]);
  $childInfo = $check->fetch(PDO::FETCH_ASSOC);

  if (!$childInfo) {
    audit_log($pdo, $userId, 'FOLLOW_UP_VISIT_DENIED', 'tbl_follow_up_visits', (string)$childSeq, 'Child is outside assigned barangay');
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Child is outside your barangay"]);
    exit;
  }
} else {
  $check = $pdo->prepare("
    SELECT ci.child_seq, ci.barangay_id, ci.c_firstname, ci.c_middlename, ci.c_lastname
    FROM tbl_child_info ci
    WHERE ci.child_seq = ?
    LIMIT 1
  ");
  $check->execute([$childSeq]);
  $childInfo = $check->fetch(PDO::FETCH_ASSOC);

  if (!$childInfo) {
    audit_log($pdo, $userId, 'FOLLOW_UP_VISIT_FAILED', 'tbl_follow_up_visits', (string)$childSeq, 'Child not found');
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Child not found"]);
    exit;
  }
}

$st = $pdo->prepare("
  INSERT INTO tbl_follow_up_visits (child_seq, user_id, note)
  VALUES (?, ?, ?)
");
$ok = $st->execute([$childSeq, $userId, $note !== "" ? $note : null]);

if ($ok) {
  $childName = trim(implode(' ', array_filter([
    $childInfo['c_firstname'] ?? '',
    $childInfo['c_middlename'] ?? '',
    $childInfo['c_lastname'] ?? ''
  ])));

  $desc = "Marked follow-up as visited for child_seq={$childSeq}";
  if ($childName !== '') {
    $desc .= " ({$childName})";
  }
  if ($note !== '') {
    $desc .= ". Note: {$note}";
  }

  audit_log($pdo, $userId, 'FOLLOW_UP_VISIT_MARKED', 'tbl_follow_up_visits', (string)$childSeq, $desc);
}

echo json_encode([
  "ok" => (bool)$ok,
  "message" => $ok ? "Follow-up marked as visited." : "Failed to save follow-up visit."
]);