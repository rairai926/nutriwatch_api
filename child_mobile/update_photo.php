<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

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
  if (ob_get_length()) {
    ob_clean();
  }
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function get_user_barangay_id(PDO $pdo, $role, $userId) {
  if ($role === 'admin') return 0;
  $st = $pdo->prepare('SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1');
  $st->execute([(int)$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);
  if ($barangayId <= 0) {
    out(403, ['ok' => false, 'message' => 'No barangay assigned']);
  }
  return $barangayId;
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'message' => 'Method not allowed']);
  }

  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower((string)($authUser->role ?? 'user'));
  $userId = (int)($authUser->sub ?? 0);

  $childSeq = (int)($_POST['child_seq'] ?? 0);
  if ($childSeq <= 0) {
    out(422, ['ok' => false, 'message' => 'Invalid child_seq']);
  }

  $barangayId = get_user_barangay_id($pdo, $role, $userId);

  $checkSql = 'SELECT child_seq FROM tbl_child_info WHERE child_seq = ?';
  $checkParams = [$childSeq];
  if ($role !== 'admin') {
    $checkSql .= ' AND barangay_id = ?';
    $checkParams[] = $barangayId;
  }
  $checkSql .= ' LIMIT 1';

  $st = $pdo->prepare($checkSql);
  $st->execute($checkParams);
  if (!$st->fetchColumn()) {
    out(404, ['ok' => false, 'message' => 'Child not found']);
  }

  if (empty($_FILES['photo']['name']) || empty($_FILES['photo']['tmp_name'])) {
    out(422, ['ok' => false, 'message' => 'No photo uploaded']);
  }

  $tmpPath = $_FILES['photo']['tmp_name'];
  $fileSize = (int)($_FILES['photo']['size'] ?? 0);
  if ($fileSize <= 0) {
    out(422, ['ok' => false, 'message' => 'Uploaded file is empty']);
  }
  if ($fileSize > 5 * 1024 * 1024) {
    out(422, ['ok' => false, 'message' => 'Photo must not exceed 5MB']);
  }

  $mime = mime_content_type($tmpPath) ?: '';
  $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($mime, $allowedMimes, true)) {
    out(422, ['ok' => false, 'message' => 'Invalid image type. Allowed: JPG, PNG, WEBP']);
  }

  $blob = file_get_contents($tmpPath);
  if ($blob === false) {
    out(500, ['ok' => false, 'message' => 'Failed to read uploaded image']);
  }

  $sql = 'UPDATE tbl_child_info SET child_photo = :child_photo WHERE child_seq = :child_seq';
  $st = $pdo->prepare($sql);
  $st->bindParam(':child_seq', $childSeq, PDO::PARAM_INT);
  $st->bindParam(':child_photo', $blob, PDO::PARAM_LOB);
  $st->execute();

  out(200, [
    'ok' => true,
    'message' => 'Photo updated successfully',
    'child_seq' => $childSeq,
    'child_photo' => 'data:' . $mime . ';base64,' . base64_encode($blob)
  ]);
} catch (Throwable $e) {
  out(500, ['ok' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
