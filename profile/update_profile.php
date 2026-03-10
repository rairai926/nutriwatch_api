<?php
require_once "../config/db.php";
require_once "../middleware/auth.php";

$user = authenticate(['admin','user','bns']);
$userId = $user->sub;

$data = json_decode(file_get_contents("php://input"), true);

$firstname = trim($data['firstname'] ?? '');
$middlename = trim($data['middlename'] ?? '');
$lastname = trim($data['lastname'] ?? '');

$sql = "
UPDATE tbl_users
SET
firstname = ?,
middlename = ?,
lastname = ?
WHERE users_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$firstname,$middlename,$lastname,$userId]);

echo json_encode(["message"=>"Profile updated"]);