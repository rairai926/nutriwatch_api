<?php
require_once '../config/db.php';
require_once '../middleware/auth.php';

$id = (int)($_POST['users_id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(["message"=>"Missing users_id"]); exit; }

$isAdmin = $authUser->role === 'admin';
$isSelf  = (int)$authUser->sub === $id;

if (!$isAdmin && !$isSelf) {
  http_response_code(403);
  echo json_encode(["message" => "Forbidden"]);
  exit;
}

$lastname   = trim($_POST['lastname'] ?? '');
$firstname  = trim($_POST['firstname'] ?? '');
$middlename = trim($_POST['middlename'] ?? '');
$email      = trim($_POST['email'] ?? '');
$username   = trim($_POST['username'] ?? '');
$status     = trim($_POST['status'] ?? '');
$barangayId = (int)($_POST['barangay_id'] ?? 0);

// only admin can change role
$role = $isAdmin ? trim($_POST['role'] ?? '') : null;

// optional password change
$newPassword = trim($_POST['password'] ?? '');
$hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;

// optional photo
$photoPath = null;
if (!empty($_FILES['photo']['name'])) {
  $dir = __DIR__ . '/../uploads/users/';
  if (!is_dir($dir)) mkdir($dir, 0777, true);

  $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg','jpeg','png','webp'];
  if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid photo type"]);
    exit;
  }

  $filename = 'user_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $target = $dir . $filename;
  if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
    http_response_code(500);
    echo json_encode(["message" => "Photo upload failed"]);
    exit;
  }
  $photoPath = 'uploads/users/' . $filename;
}

$fields = ["lastname=?", "firstname=?", "middlename=?", "email=?", "username=?"];
$params = [$lastname, $firstname, $middlename, $email, $username];

if ($isAdmin && $role !== '') { $fields[] = "role=?"; $params[] = $role; }
if ($isAdmin && $barangayId > 0) { $fields[] = "barangay_id=?"; $params[] = $barangayId; }
if ($isAdmin && $status !== '') { $fields[] = "status=?"; $params[] = $status; }
if ($hash) { $fields[] = "password=?"; $params[] = $hash; }
if ($photoPath) { $fields[] = "photo=?"; $params[] = $photoPath; }

$params[] = $id;

$sql = "UPDATE tbl_users SET " . implode(',', $fields) . " WHERE users_id=?";
$pdo->prepare($sql)->execute($params);

echo json_encode(["message" => "User updated successfully"]);
