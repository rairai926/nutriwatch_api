<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';

$authUser = authenticate(['admin', 'user']);

echo json_encode([
  "message" => "You are authenticated",
  "user" => $authUser
]);