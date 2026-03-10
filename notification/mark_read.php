<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://nutriwatch.com",
  "http://192.168.1.36:3000"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  echo json_encode(["message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $userId = (int)($authUser->sub ?? 0);

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) $data = [];

  $notifType = trim((string)($data['notif_type'] ?? ''));
  $notifRefId = (int)($data['notif_ref_id'] ?? 0);

  if ($notifType === '' || $notifRefId <= 0) {
    out(422, ['message' => 'Invalid notification']);
  }

  $sql = "
    INSERT IGNORE INTO tbl_notification_reads (users_id, notif_type, notif_ref_id)
    VALUES (?, ?, ?)
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$userId, $notifType, $notifRefId]);

  out(200, ['message' => 'Notification marked as read']);
} catch (Throwable $e) {
  out(500, [
    'message' => 'Server error',
    'error' => $e->getMessage()
  ]);
}