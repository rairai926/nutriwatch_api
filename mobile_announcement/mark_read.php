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
header('Access-Control-Allow-Methods: POST, OPTIONS');

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

  $data = json_decode(file_get_contents('php://input'), true);
  if (!is_array($data)) $data = [];

  $announcementId = (int)($data['announcement_id'] ?? 0);
  if ($announcementId <= 0) {
    out(422, ['message' => 'announcement_id is required']);
  }

  $barangayId = 0;
  if ($role !== 'admin') {
    $scopeStmt = $pdo->prepare('SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1');
    $scopeStmt->execute([$userId]);
    $barangayId = (int)($scopeStmt->fetchColumn() ?: 0);

    if ($barangayId <= 0) {
      out(403, ['message' => 'No barangay assigned']);
    }
  }

  if ($role === 'admin') {
    $checkSql = 'SELECT announcement_id FROM tbl_announcement WHERE announcement_id = ? AND active = 1 LIMIT 1';
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$announcementId]);
  } else {
    $checkSql = '
      SELECT announcement_id
      FROM tbl_announcement
      WHERE announcement_id = ?
        AND active = 1
        AND (is_global = 1 OR (is_global = 0 AND barangay_id = ?))
      LIMIT 1
    ';
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$announcementId, $barangayId]);
  }

  if (!$checkStmt->fetch()) {
    out(404, ['message' => 'Announcement not found']);
  }

  $stmt = $pdo->prepare('
    INSERT INTO tbl_announcement_reads (announcement_id, users_id, read_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)
  ');
  $stmt->execute([$announcementId, $userId]);

  echo json_encode(['message' => 'Announcement marked as read']);
} catch (Throwable $e) {
  out(500, [
    'message' => 'Server error',
    'error' => $e->getMessage()
  ]);
}
