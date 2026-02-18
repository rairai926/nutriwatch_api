<?php
require_once  '../vendor/autoload.php';
require_once  '../config/db.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json");

function requireRole(array $allowedRoles) {
  $headers = getallheaders();
  $auth = $headers['Authorization'] ?? '';

  if (!preg_match('/Bearer\s(\S+)/', $auth, $m)) {
    http_response_code(401);
    echo json_encode(["message" => "Unauthorized"]);
    exit;
  }

  $token = $m[1];

  try {
    $secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';
    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));

    $role = $decoded->role ?? null;

    if (!$role || !in_array($role, $allowedRoles, true)) {
      http_response_code(403);
      echo json_encode(["message" => "Forbidden"]);
      exit;
    }

    return $decoded; // ✅ gives you user info if needed
  } catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid or expired token"]);
    exit;
  }
}
