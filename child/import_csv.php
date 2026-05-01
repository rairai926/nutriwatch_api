<?php

ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// --------------------
// CORS
// --------------------
$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://nutriwatch.com",
  "http://192.168.1.36:3000",
  "https://nutriwatch-cyan.vercel.app"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";
require_once __DIR__ . "/../helpers/nutrition_status_helper.php";

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function clean_value($value) {
  return trim((string)$value);
}

function normalize_sex($sex) {
  $sex = strtolower(trim((string)$sex));

  if (in_array($sex, ['m', 'male', 'boy', 'boys'], true)) {
    return 'Male';
  }

  if (in_array($sex, ['f', 'female', 'girl', 'girls'], true)) {
    return 'Female';
  }

  return '';
}

function normalize_yes_no($value) {
  $value = strtolower(trim((string)$value));

  if (in_array($value, ['yes', 'y', '1', 'true'], true)) {
    return 'Yes';
  }

  if (in_array($value, ['no', 'n', '0', 'false'], true)) {
    return 'No';
  }

  return '';
}

function normalize_date($value) {
  $value = trim((string)$value);

  if ($value === '') {
    return '';
  }

  $formats = [
    'Y-m-d',
    'm/d/Y',
    'd/m/Y',
    'm-d-Y',
    'd-m-Y',
    'M d, Y',
    'F d, Y'
  ];

  foreach ($formats as $format) {
    $date = DateTime::createFromFormat($format, $value);
    if ($date && $date->format($format) === $value) {
      return $date->format('Y-m-d');
    }
  }

  $timestamp = strtotime($value);
  if ($timestamp !== false) {
    return date('Y-m-d', $timestamp);
  }

  return '';
}

function split_person_name($fullName) {
  $fullName = trim((string)$fullName);

  $result = [
    'lastname' => '',
    'firstname' => '',
    'middlename' => ''
  ];

  if ($fullName === '') {
    return $result;
  }

  // Format: LASTNAME, FIRSTNAME MIDDLENAME
  if (strpos($fullName, ',') !== false) {
    [$lastname, $rest] = array_map('trim', explode(',', $fullName, 2));

    $parts = preg_split('/\s+/', $rest);
    $firstname = $parts[0] ?? '';
    $middlename = trim(implode(' ', array_slice($parts, 1)));

    $result['lastname'] = $lastname;
    $result['firstname'] = $firstname;
    $result['middlename'] = $middlename;

    return $result;
  }

  // Format: FIRSTNAME MIDDLENAME LASTNAME
  $parts = preg_split('/\s+/', $fullName);

  $result['firstname'] = $parts[0] ?? '';
  $result['lastname'] = count($parts) > 1 ? end($parts) : '';
  $result['middlename'] = count($parts) > 2
    ? implode(' ', array_slice($parts, 1, -1))
    : '';

  return $result;
}

function audit_log(PDO $pdo, ?int $userId, string $action, ?string $targetTable, ?string $targetId, ?string $description): void {
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
      $ip = trim(explode(',', $ip)[0]);
    }

    $st = $pdo->prepare("
      INSERT INTO tbl_audit_logs 
        (user_id, action, target_table, target_id, description, ip_address)
      VALUES 
        (?, ?, ?, ?, ?, ?)
    ");

    $st->execute([
      $userId,
      $action,
      $targetTable,
      $targetId,
      $description,
      $ip !== '' ? $ip : null
    ]);
  } catch (Throwable $e) {
    error_log("Audit log failed: " . $e->getMessage());
  }
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    out(422, [
      "ok" => false,
      "message" => "CSV file is required"
    ]);
  }

  $filePath = $_FILES['csv_file']['tmp_name'];
  $handle = fopen($filePath, 'r');

  if (!$handle) {
    out(500, [
      "ok" => false,
      "message" => "Unable to read CSV file"
    ]);
  }

  $headers = fgetcsv($handle);

  if (!$headers) {
    out(422, [
      "ok" => false,
      "message" => "CSV header row is missing"
    ]);
  }

  $headers = array_map(function ($h) {
    return strtolower(trim($h));
  }, $headers);

  $requiredHeaders = [
    'barangay_id',
    'address or location',
    'name of mother/guardian',
    'full name of child',
    'belongs to ip group?',
    'sex',
    'date of birth',
    'date measured',
    'weight',
    'height'
  ];

  foreach ($requiredHeaders as $required) {
    if (!in_array($required, $headers, true)) {
      out(422, [
        "ok" => false,
        "message" => "Missing required CSV column: {$required}"
      ]);
    }
  }

  // For non-admin users, get assigned barangay once
  $assignedBarangayId = 0;

  if ($role !== 'admin') {
    $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $st->execute([$userId]);
    $assignedBarangayId = (int)($st->fetchColumn() ?: 0);

    if ($assignedBarangayId <= 0) {
      out(403, [
        "ok" => false,
        "message" => "No barangay assigned"
      ]);
    }
  }

  $success = 0;
  $skipped = 0;
  $failed = 0;
  $errors = [];
  $rowNumber = 1;

  $pdo->beginTransaction();

  while (($row = fgetcsv($handle)) !== false) {
    $rowNumber++;

    try {
      $data = [];

      foreach ($headers as $index => $header) {
        $data[$header] = $row[$index] ?? '';
      }

      $barangayIdFromCsv = (int)clean_value($data['barangay_id'] ?? 0);

      if ($role === 'admin') {
        if ($barangayIdFromCsv <= 0) {
          throw new Exception("Invalid barangay_id");
        }

        $barangayIdFinal = $barangayIdFromCsv;
      } else {
        // Non-admin/BNS cannot override barangay from CSV
        $barangayIdFinal = $assignedBarangayId;
      }

      $purok = clean_value($data['address or location'] ?? '');
      $guardianName = clean_value($data['name of mother/guardian'] ?? '');
      $childName = clean_value($data['full name of child'] ?? '');
      $ipGroup = normalize_yes_no($data['belongs to ip group?'] ?? '');
      $sex = normalize_sex($data['sex'] ?? '');
      $dateBirth = normalize_date($data['date of birth'] ?? '');
      $dateMeasured = normalize_date($data['date measured'] ?? '');

      $weight = clean_value($data['weight'] ?? '');
      $height = clean_value($data['height'] ?? '');

      $weight = $weight !== '' ? (float)$weight : null;
      $height = $height !== '' ? (float)$height : null;

      $guardian = split_person_name($guardianName);
      $child = split_person_name($childName);

      if ($child['firstname'] === '' || $child['lastname'] === '') {
        throw new Exception("Child name is incomplete");
      }

      if ($sex === '') {
        throw new Exception("Invalid sex value");
      }

      if ($dateBirth === '') {
        throw new Exception("Invalid date of birth");
      }

      if ($dateMeasured === '') {
        throw new Exception("Invalid date measured");
      }

      if ($weight === null || $weight <= 0) {
        throw new Exception("Invalid weight");
      }

      if ($height === null || $height <= 0) {
        throw new Exception("Invalid height");
      }

      // Check duplicate child
      $findChild = $pdo->prepare("
        SELECT child_seq
        FROM tbl_child_info
        WHERE barangay_id = ?
          AND LOWER(TRIM(c_firstname)) = LOWER(TRIM(?))
          AND LOWER(TRIM(c_lastname)) = LOWER(TRIM(?))
          AND date_birth = ?
        LIMIT 1
      ");

      $findChild->execute([
        $barangayIdFinal,
        $child['firstname'],
        $child['lastname'],
        $dateBirth
      ]);

      $childSeq = (int)($findChild->fetchColumn() ?: 0);

      // Insert child if not existing
      if ($childSeq <= 0) {
        $insertChild = $pdo->prepare("
          INSERT INTO tbl_child_info
            (
              province_id, city_id, barangay_id, purok,
              g_lastname, g_firstname, g_middlename,
              c_lastname, c_firstname, c_middlename,
              ip_group, sex, date_birth, disability,
              user_id
            )
          VALUES
            (
              :province_id, :city_id, :barangay_id, :purok,
              :g_lastname, :g_firstname, :g_middlename,
              :c_lastname, :c_firstname, :c_middlename,
              :ip_group, :sex, :date_birth, :disability,
              :user_id
            )
        ");

        $insertChild->execute([
          ':province_id' => 1,
          ':city_id' => 1,
          ':barangay_id' => $barangayIdFinal,
          ':purok' => $purok !== '' ? $purok : null,

          ':g_lastname' => $guardian['lastname'] !== '' ? $guardian['lastname'] : null,
          ':g_firstname' => $guardian['firstname'] !== '' ? $guardian['firstname'] : null,
          ':g_middlename' => $guardian['middlename'] !== '' ? $guardian['middlename'] : null,

          ':c_lastname' => $child['lastname'],
          ':c_firstname' => $child['firstname'],
          ':c_middlename' => $child['middlename'] !== '' ? $child['middlename'] : null,

          ':ip_group' => $ipGroup !== '' ? $ipGroup : null,
          ':sex' => $sex,
          ':date_birth' => $dateBirth,
          ':disability' => null,

          ':user_id' => $userId
        ]);

        $childSeq = (int)$pdo->lastInsertId();
      }

      // Skip duplicate measurement for the same child and same date
      $checkMeasurement = $pdo->prepare("
        SELECT measure_id
        FROM tbl_measurement
        WHERE child_seq = ?
          AND date_measured = ?
        LIMIT 1
      ");

      $checkMeasurement->execute([$childSeq, $dateMeasured]);

      if ($checkMeasurement->fetchColumn()) {
        $skipped++;
        continue;
      }

      // Validate height range based on age
      $ageMonthsPreview = nh_compute_age_months($dateBirth, $dateMeasured);

      if ($ageMonthsPreview <= 23) {
        if ($height < 45 || $height > 110) {
          throw new Exception("For 0–23 months, length must be between 45 and 110 cm");
        }
      } else {
        if ($height < 65 || $height > 120) {
          throw new Exception("For 24+ months, height must be between 65 and 120 cm");
        }
      }

      // Auto-compute age and nutritional status
      $statuses = nh_compute_all_statuses(
        $sex,
        $dateBirth,
        $dateMeasured,
        $weight,
        $height,
        null,
        'No'
      );

      $insertMeasurement = $pdo->prepare("
        INSERT INTO tbl_measurement
          (
            child_seq,
            user_id,
            date_measured,
            weight,
            height,
            muac,
            age_months,
            age_days,
            weight_status,
            height_status,
            lt_status,
            muac_status,
            bilateral_pitting,
            assessment_method
          )
        VALUES
          (
            :child_seq,
            :user_id,
            :date_measured,
            :weight,
            :height,
            :muac,
            :age_months,
            :age_days,
            :weight_status,
            :height_status,
            :lt_status,
            :muac_status,
            :bilateral_pitting,
            :assessment_method
          )
      ");

      $insertMeasurement->execute([
        ':child_seq' => $childSeq,
        ':user_id' => $userId,
        ':date_measured' => $dateMeasured,
        ':weight' => $weight,
        ':height' => $height,
        ':muac' => null,
        ':age_months' => $statuses['age_months'],
        ':age_days' => $statuses['age_days'],
        ':weight_status' => $statuses['weight_status'],
        ':height_status' => $statuses['height_status'],
        ':lt_status' => $statuses['lt_status'],
        ':muac_status' => $statuses['muac_status'],
        ':bilateral_pitting' => 'No',
        ':assessment_method' => 'Weight + Length/Height'
      ]);

      $success++;

    } catch (Throwable $e) {
      $failed++;
      $errors[] = [
        "row" => $rowNumber,
        "message" => $e->getMessage()
      ];
    }
  }

  fclose($handle);

  $pdo->commit();

  audit_log(
    $pdo,
    $userId,
    'CSV_IMPORT_CHILD_MEASUREMENT',
    'tbl_measurement',
    null,
    "CSV import completed. Success={$success}, Skipped={$skipped}, Failed={$failed}"
  );

  out(200, [
    "ok" => true,
    "message" => "CSV import completed",
    "success" => $success,
    "skipped" => $skipped,
    "failed" => $failed,
    "errors" => $errors
  ]);

} catch (Throwable $e) {
  if (isset($handle) && is_resource($handle)) {
    fclose($handle);
  }

  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}