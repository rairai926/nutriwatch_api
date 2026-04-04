<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/../config/db.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function audit_log(PDO $pdo, ?int $userId, string $action, ?string $targetTable, ?string $targetId, ?string $description): void {
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'SYSTEM';
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
      $ip !== '' ? $ip : 'SYSTEM'
    ]);
  } catch (Throwable $e) {
    error_log("Audit log failed: " . $e->getMessage());
  }
}

try {
  // find children 60 months or older, still active, not yet archived
  $findSql = "
    SELECT
      ci.child_seq,
      ci.province_id,
      ci.city_id,
      ci.barangay_id,
      ci.purok,
      ci.g_lastname,
      ci.g_firstname,
      ci.g_middlename,
      ci.c_lastname,
      ci.c_firstname,
      ci.c_middlename,
      ci.ip_group,
      ci.sex,
      ci.date_birth,
      ci.disability,
      ci.user_id
    FROM tbl_child_info ci
    WHERE ci.date_birth IS NOT NULL
      AND TIMESTAMPDIFF(MONTH, ci.date_birth, CURDATE()) >= 60
      AND NOT EXISTS (
        SELECT 1
        FROM tbl_child_archive ca
        WHERE ca.child_seq = ci.child_seq
      )
    ORDER BY ci.child_seq ASC
  ";

  $rows = $pdo->query($findSql)->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    out(200, [
      "ok" => true,
      "message" => "No children qualified for auto-archive.",
      "archived_count" => 0
    ]);
  }

  $pdo->beginTransaction();

  $insertSql = "
    INSERT INTO tbl_child_archive (
      child_seq,
      province_id,
      city_id,
      barangay_id,
      purok,
      g_lastname,
      g_firstname,
      g_middlename,
      c_lastname,
      c_firstname,
      c_middlename,
      ip_group,
      sex,
      date_birth,
      disability,
      user_id,
      archived_at,
      archive_reason,
      archived_by_user_id
    )
    VALUES (
      :child_seq,
      :province_id,
      :city_id,
      :barangay_id,
      :purok,
      :g_lastname,
      :g_firstname,
      :g_middlename,
      :c_lastname,
      :c_firstname,
      :c_middlename,
      :ip_group,
      :sex,
      :date_birth,
      :disability,
      :user_id,
      NOW(),
      :archive_reason,
      NULL
    )
  ";

  $insertSt = $pdo->prepare($insertSql);
  $deleteSt = $pdo->prepare("DELETE FROM tbl_child_info WHERE child_seq = ?");

  $archivedCount = 0;

  foreach ($rows as $r) {
    $insertSt->execute([
      ':child_seq' => (int)$r['child_seq'],
      ':province_id' => $r['province_id'],
      ':city_id' => $r['city_id'],
      ':barangay_id' => $r['barangay_id'],
      ':purok' => $r['purok'],
      ':g_lastname' => $r['g_lastname'],
      ':g_firstname' => $r['g_firstname'],
      ':g_middlename' => $r['g_middlename'],
      ':c_lastname' => $r['c_lastname'],
      ':c_firstname' => $r['c_firstname'],
      ':c_middlename' => $r['c_middlename'],
      ':ip_group' => $r['ip_group'],
      ':sex' => $r['sex'],
      ':date_birth' => $r['date_birth'],
      ':disability' => $r['disability'],
      ':user_id' => $r['user_id'],
      ':archive_reason' => 'Automatically archived after reaching 60 months of age'
    ]);

    $deleteSt->execute([(int)$r['child_seq']]);

    $childName = trim(implode(' ', array_filter([
      $r['c_firstname'] ?? '',
      $r['c_middlename'] ?? '',
      $r['c_lastname'] ?? ''
    ])));

    audit_log(
      $pdo,
      null,
      'CHILD_AUTO_ARCHIVED',
      'tbl_child_archive',
      (string)$r['child_seq'],
      "Auto-archived child_seq={$r['child_seq']}" . ($childName !== '' ? " ({$childName})" : '') . " after reaching 60 months"
    );

    $archivedCount++;
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Auto-archive completed successfully.",
    "archived_count" => $archivedCount
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  audit_log(
    $pdo,
    null,
    'CHILD_AUTO_ARCHIVE_FAILED',
    'tbl_child_archive',
    null,
    $e->getMessage()
  );

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}