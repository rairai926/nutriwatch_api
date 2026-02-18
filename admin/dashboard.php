<?php
require_once '../middleware/auth.php';

// only admins allowed
$user = authenticate(['admin']);

echo json_encode([
    "message" => "Welcome admin",
    "user" => $user
]);
