<?php
require_once '../config/db.php';
require_once '../middleware/auth.php';

$role = $authUser->role;
$userId = (int)$authUser->sub;

if ($role === 'admin') {
  $stmt = $pdo->query("SELECT users_id, lastname, firstname, middlename, email, username, role, barangay_id, status, photo, created_at
                       FROM tbl_users ORDER BY users_id DESC");
  echo json_encode($stmt->fetchAll());
  exit;
}

// user role: only self
$stmt = $pdo->prepare("SELECT users_id, lastname, firstname, middlename, email, username, role, barangay_id, status, photo, created_at
                       FROM tbl_users WHERE users_id = ? LIMIT 1");
$stmt->execute([$userId]);
echo json_encode($stmt->fetchAll());
