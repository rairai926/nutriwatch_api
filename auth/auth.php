<?php

  ob_start(); // optional safety

  header("Access-Control-Allow-Origin: *");
  header("Access-Control-Allow-Headers: Content-Type, Authorization");
  header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
  header("Content-Type: application/json");

  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
  }
  
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/* ================== HEADERS ================== */

/* ================== TOKEN ================== */
$headers = getallheaders();

if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(["message" => "Missing Authorization header"]);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);

try {
    $secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';

    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));

    // ✅ token valid — user info available
    $authUser = $decoded;

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["message" => "Invalid or expired token"]);
    exit;
}
