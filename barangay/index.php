<?php
require_once '../config/db.php';
require_once '../middleware/auth.php'; // optional, but recommended (only logged-in can fetch)

$stmt = $pdo->query("SELECT barangay_id, barangay_name FROM tbl_barangay ORDER BY barangay_name ASC");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
