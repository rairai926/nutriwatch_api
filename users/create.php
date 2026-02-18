<?php
require_once '../config/db.php';
require_once '../middleware/auth.php';

if ($authUser->role !== 'admin') {
  http_response_code(403);
  echo json_encode(["message" => "Forbidden"]);
  exit;
}

$lastname   = trim($_POST['lastname'] ?? '');
$firstname  = trim($_POST['firstname'] ?? '');
$middlename = trim($_POST['middlename'] ?? '');
$email      = trim($_POST['email'] ?? '');
$username   = trim($_POST['username'] ?? '');
$password   = trim($_POST['password'] ?? '');
$role       = trim($_POST['role'] ?? 'user'); // admin chooses
$barangayId = (int)($_POST['barangay_id'] ?? 0);
$status     = trim($_POST['status'] ?? 'active');

if ($lastname==='' || $firstname==='' || $email==='' || $username==='' || $password==='') {
  http_response_code(400);
  echo json_encode(["message" => "Missing required fields"]);
  exit;
}

$hash = password_hash($password, PASSWORD_ARGON2ID);

// photo upload (optional)
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

  $photoPath = 'uploads/users/' . $filename; // store relative path
}

$stmt = $pdo->prepare("INSERT INTO tbl_users (lastname, firstname, middlename, email, username, password, role, barangay_id, status, photo, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->execute([$lastname, $firstname, $middlename, $email, $username, $hash, $role, $barangayId, $status, $photoPath]);

echo json_encode(["message" => "User created successfully"]);
