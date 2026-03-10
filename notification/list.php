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
header("Access-Control-Allow-Methods: GET, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
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
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = 0;
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id=? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  $items = [];

  // -----------------------------
  // Announcements
  // -----------------------------
  if ($role === 'admin') {
    $sql = "
      SELECT
        'announcement' AS notif_type,
        a.announcement_id AS notif_ref_id,
        a.announcement_title AS title,
        LEFT(a.message, 120) AS description,
        CONCAT(COALESCE(a.date_posted, CURDATE()), ' ', COALESCE(a.time_start, '00:00:00')) AS created_at,
        'announcement' AS icon,
        CASE WHEN nr.read_id IS NULL THEN 0 ELSE 1 END AS is_read
      FROM tbl_announcement a
      LEFT JOIN tbl_notification_reads nr
        ON nr.users_id = :users_id
       AND nr.notif_type = 'announcement'
       AND nr.notif_ref_id = a.announcement_id
      WHERE a.active = 1
        AND CONCAT(a.date_start, ' ', COALESCE(a.time_start, '00:00:00')) <= NOW()
        AND CONCAT(a.date_end, ' ', COALESCE(a.time_end, '23:59:59')) >= NOW()
      ORDER BY a.announcement_id DESC
      LIMIT 5
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':users_id' => $userId
    ]);
    $items = array_merge($items, $st->fetchAll(PDO::FETCH_ASSOC));
  } else {
    $sql = "
      SELECT
        'announcement' AS notif_type,
        a.announcement_id AS notif_ref_id,
        a.announcement_title AS title,
        LEFT(a.message, 120) AS description,
        CONCAT(COALESCE(a.date_posted, CURDATE()), ' ', COALESCE(a.time_start, '00:00:00')) AS created_at,
        'announcement' AS icon,
        CASE WHEN nr.read_id IS NULL THEN 0 ELSE 1 END AS is_read
      FROM tbl_announcement a
      LEFT JOIN tbl_notification_reads nr
        ON nr.users_id = :users_id
       AND nr.notif_type = 'announcement'
       AND nr.notif_ref_id = a.announcement_id
      WHERE a.active = 1
        AND CONCAT(a.date_start, ' ', COALESCE(a.time_start, '00:00:00')) <= NOW()
        AND CONCAT(a.date_end, ' ', COALESCE(a.time_end, '23:59:59')) >= NOW()
        AND (
          a.is_global = 1
          OR a.barangay_id = :barangay_id
        )
      ORDER BY a.announcement_id DESC
      LIMIT 5
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':users_id' => $userId,
      ':barangay_id' => $barangayId
    ]);
    $items = array_merge($items, $st->fetchAll(PDO::FETCH_ASSOC));
  }

  // -----------------------------
  // Admin-only: recent child
  // -----------------------------
  if ($role === 'admin') {
    $sqlChild = "
      SELECT
        'child' AS notif_type,
        ci.child_seq AS notif_ref_id,
        CONCAT('New child added: ', ci.c_firstname, ' ', ci.c_lastname) AS title,
        CONCAT('Barangay: ', COALESCE(TRIM(b.barangay_name), 'N/A')) AS description,
        NOW() AS created_at,
        'child' AS icon,
        CASE WHEN nr.read_id IS NULL THEN 0 ELSE 1 END AS is_read
      FROM tbl_child_info ci
      LEFT JOIN tbl_barangay b
        ON b.barangay_id = ci.barangay_id
      LEFT JOIN tbl_notification_reads nr
        ON nr.users_id = :users_id
       AND nr.notif_type = 'child'
       AND nr.notif_ref_id = ci.child_seq
      ORDER BY ci.child_seq DESC
      LIMIT 5
    ";
    $st = $pdo->prepare($sqlChild);
    $st->execute([
      ':users_id' => $userId
    ]);
    $items = array_merge($items, $st->fetchAll(PDO::FETCH_ASSOC));

    // -----------------------------
    // Admin-only: recent measurement
    // -----------------------------
    $sqlMeasure = "
      SELECT
        'measurement' AS notif_type,
        m.measure_id AS notif_ref_id,
        CONCAT('New measurement: ', ci.c_firstname, ' ', ci.c_lastname) AS title,
        CONCAT('Measured on ', m.date_measured) AS description,
        CONCAT(m.date_measured, ' 00:00:00') AS created_at,
        'measurement' AS icon,
        CASE WHEN nr.read_id IS NULL THEN 0 ELSE 1 END AS is_read
      FROM tbl_measurement m
      JOIN tbl_child_info ci
        ON ci.child_seq = m.child_seq
      LEFT JOIN tbl_notification_reads nr
        ON nr.users_id = :users_id
       AND nr.notif_type = 'measurement'
       AND nr.notif_ref_id = m.measure_id
      ORDER BY m.measure_id DESC
      LIMIT 5
    ";
    $st = $pdo->prepare($sqlMeasure);
    $st->execute([
      ':users_id' => $userId
    ]);
    $items = array_merge($items, $st->fetchAll(PDO::FETCH_ASSOC));
  }

  usort($items, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
  });

  $items = array_slice($items, 0, 5);

  $unread = 0;
  foreach ($items as $it) {
    if ((int)$it['is_read'] === 0) {
      $unread++;
    }
  }

  out(200, [
    'unread' => $unread,
    'rows' => $items
  ]);
} catch (Throwable $e) {
  out(500, [
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}