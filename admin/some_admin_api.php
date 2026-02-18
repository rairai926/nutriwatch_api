<?php
require_once __DIR__ . '/../auth/require_role.php';

$user = requireRole(['admin']); // ✅ only CSWD staff

echo json_encode(["message" => "Admin OK", "user" => $user]);
