  <?php
  require_once '../vendor/autoload.php';
  require_once '../config/db.php';

  use Firebase\JWT\JWT;

  

  /* INPUT */
  $data = json_decode(file_get_contents("php://input"), true);

  $username = trim($data['username'] ?? '');
  $password = trim($data['password'] ?? '');

  if ($username === '' || $password === '') {
      http_response_code(400);
      echo json_encode(["message" => "Missing credentials"]);
      exit;
  }

  /* USER */
  $stmt = $pdo->prepare("SELECT users_id, username, password, role FROM tbl_users WHERE username = ? LIMIT 1");
  $stmt->execute([$username]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);


  if (!$user || !password_verify($password, $user['password'])) {
      http_response_code(401);
      echo json_encode(["message" => "Invalid username or password"]);
      exit;
  }

  /* JWT */
  $secretKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123!@#';

  $payload = [
    "iss" => "my-app",
    "iat" => time(),
    "exp" => time() + 3600,
    "sub" => $user['users_id'],
    "username" => $user['username'],
    "role" => $user['role'] // ✅ add this
  ];

  $jwt = JWT::encode($payload, $secretKey, 'HS256');

  echo json_encode([
    "token" => $jwt,
    "user" => [
      "id" => $user['users_id'],
      "username" => $user['username'],
      "role" => $user['role']
    ]
  ]);

