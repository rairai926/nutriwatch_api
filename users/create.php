<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin']);

$lastname   = trim($_POST['lastname'] ?? '');
$firstname  = trim($_POST['firstname'] ?? '');
$middlename = trim($_POST['middlename'] ?? '');
$email      = trim($_POST['email'] ?? '');
$username   = trim($_POST['username'] ?? '');
$password   = trim($_POST['password'] ?? '');
$role       = trim($_POST['role'] ?? 'user');
$barangayId = (int)($_POST['barangay_id'] ?? 0);
$status     = trim($_POST['status'] ?? 'active');

if ($lastname === '' || $firstname === '' || $email === '' || $username === '' || $password === '') {
  http_response_code(400);
  echo json_encode(["message" => "Missing required fields"]);
  exit;
}

$hash = password_hash($password, PASSWORD_ARGON2ID);

$stmt = $pdo->prepare("
  INSERT INTO tbl_users
    (lastname, firstname, middlename, email, username, password, role, barangay_id, status, created_at)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
  $lastname,
  $firstname,
  $middlename,
  $email,
  $username,
  $hash,
  $role,
  $barangayId,
  $status
]);

echo json_encode(["message" => "User created successfully"]);