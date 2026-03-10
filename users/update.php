<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin', 'user']);

$id = (int)($_POST['users_id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["message" => "Missing users_id"]);
  exit;
}

$isAdmin = (($authUser->role ?? '') === 'admin');
$isSelf  = ((int)($authUser->sub ?? 0) === $id);

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

$role = $isAdmin ? trim($_POST['role'] ?? '') : null;

$newPassword = trim($_POST['password'] ?? '');
$hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;

$fields = [];
$params = [];

if ($lastname !== '')   { $fields[] = "lastname=?";   $params[] = $lastname; }
if ($firstname !== '')  { $fields[] = "firstname=?";  $params[] = $firstname; }
if ($middlename !== '') { $fields[] = "middlename=?"; $params[] = $middlename; }
if ($email !== '')      { $fields[] = "email=?";      $params[] = $email; }
if ($username !== '')   { $fields[] = "username=?";   $params[] = $username; }

if ($isAdmin && $role !== '')    { $fields[] = "role=?";        $params[] = $role; }
if ($isAdmin && $barangayId > 0) { $fields[] = "barangay_id=?"; $params[] = $barangayId; }
if ($isAdmin && $status !== '')  { $fields[] = "status=?";      $params[] = $status; }

if ($hash) { $fields[] = "password=?"; $params[] = $hash; }

if (empty($fields)) {
  http_response_code(400);
  echo json_encode(["message" => "No fields to update"]);
  exit;
}

$params[] = $id;

$sql = "UPDATE tbl_users SET " . implode(", ", $fields) . " WHERE users_id=?";
$pdo->prepare($sql)->execute($params);

echo json_encode(["message" => "User updated successfully"]);