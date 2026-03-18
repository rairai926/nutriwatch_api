<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

$allowedOrigins = [
  'http://localhost:3000',
  'http://127.0.0.1:3000',
  'https://nutriwatch.com',
  'http://192.168.1.36:3000'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(200);
  exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower((string)($authUser->role ?? 'user'));
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = 0;
  if ($role !== 'admin') {
    $scopeStmt = $pdo->prepare('SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1');
    $scopeStmt->execute([$userId]);
    $barangayId = (int)($scopeStmt->fetchColumn() ?: 0);

    if ($barangayId <= 0) {
      out(403, ['message' => 'No barangay assigned']);
    }
  }

  $params = [$userId];
  $scopeSql = '';

  if ($role !== 'admin') {
    $scopeSql = ' AND (a.is_global = 1 OR (a.is_global = 0 AND a.barangay_id = ?)) ';
    $params[] = $barangayId;
  }

  $sql = "
    SELECT
      a.announcement_id,
      a.announcement_title AS title,
      a.message,
      a.date_start,
      a.time_start,
      a.date_end,
      a.time_end,
      a.date_posted,
      a.venue,
      a.is_global,
      a.barangay_id,
      b.barangay_name,
      CASE
        WHEN a.is_global = 1 THEN 'Global Announcement'
        WHEN b.barangay_name IS NOT NULL THEN CONCAT('For ', b.barangay_name)
        ELSE 'Specific Barangay'
      END AS audience_label,
      CASE WHEN r.announcement_id IS NULL THEN 0 ELSE 1 END AS is_read
    FROM tbl_announcement a
    LEFT JOIN tbl_barangay b ON b.barangay_id = a.barangay_id
    LEFT JOIN tbl_announcement_reads r
      ON r.announcement_id = a.announcement_id
     AND r.users_id = ?
    WHERE a.active = 1
      AND CURDATE() BETWEEN a.date_start AND a.date_end
      $scopeSql
    ORDER BY
      CASE WHEN r.announcement_id IS NULL THEN 0 ELSE 1 END ASC,
      a.date_posted DESC,
      a.announcement_id DESC
    LIMIT 30
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$row) {
    $row['announcement_id'] = (int)($row['announcement_id'] ?? 0);
    $row['is_global'] = (int)($row['is_global'] ?? 0);
    $row['barangay_id'] = isset($row['barangay_id']) ? (int)$row['barangay_id'] : null;
    $row['is_read'] = (int)($row['is_read'] ?? 0);
  }

  echo json_encode($rows);
} catch (Throwable $e) {
  out(500, [
    'message' => 'Server error',
    'error' => $e->getMessage()
  ]);
}
