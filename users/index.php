<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin', 'user']); // adjust roles if needed

$role = $authUser->role ?? 'user';
$userId = (int)($authUser->sub ?? 0);

if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(["message" => "Unauthorized"]);
  exit;
}

if ($role === 'admin') {
  $stmt = $pdo->query("
    SELECT users_id, lastname, firstname, middlename, email, username, role, barangay_id, status, photo, created_at
    FROM tbl_users
    ORDER BY users_id DESC
  ");
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
  exit;
}

// non-admin: only self
$stmt = $pdo->prepare("
  SELECT users_id, lastname, firstname, middlename, email, username, role, barangay_id, status, photo, created_at
  FROM tbl_users
  WHERE users_id = ?
  LIMIT 1
");
$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($user ? $user : []);