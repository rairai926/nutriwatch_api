<?php
require '../auth/auth.php';
require '../config/db.php';

echo json_encode([
    "message" => "You are authenticated",
    "user" => $authUser
]);
