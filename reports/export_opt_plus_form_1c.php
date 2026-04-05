<?php
ob_start();
session_start();

$allowedOrigins = [
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://nutriwatch.com",
  "http://192.168.1.36:3000"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Vary: Origin");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
  http_response_code(405);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode([
    "ok" => false,
    "message" => "Method not allowed"
  ]);
  exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function json_out($code, $payload) {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function audit_log(PDO $pdo, ?int $userId, string $action, ?string $targetTable, ?string $targetId, ?string $description): void {
  try {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
      $ip = trim(explode(',', $ip)[0]);
    }

    $st = $pdo->prepare("
      INSERT INTO tbl_audit_logs (user_id, action, target_table, target_id, description, ip_address)
      VALUES (?, ?, ?, ?, ?, ?)
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

function normalize_text($value): string {
  $value = strtoupper(trim((string)$value));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value;
}

function clean_person_name($last, $first, $middle = ''): string {
  $last = trim((string)$last);
  $first = trim((string)$first);
  $middle = trim((string)$middle);

  $name = $last;
  if ($first !== '') {
    $name .= ($name !== '' ? ', ' : '') . $first;
  }
  if ($middle !== '') {
    $name .= ' ' . $middle;
  }

  return trim($name);
}

function sex_value($sex): string {
  $s = normalize_text($sex);
  if (in_array($s, ['M', 'MALE'], true)) return 'M';
  if (in_array($s, ['F', 'FEMALE'], true)) return 'F';
  return trim((string)$sex);
}

function normalize_wfa($status): ?string {
  $s = normalize_text($status);

  if (in_array($s, ['MODERATELY UNDERWEIGHT', 'MUW', 'UNDERWEIGHT', 'UW'], true)) return 'MUW';
  if (in_array($s, ['SEVERELY UNDERWEIGHT', 'SUW'], true)) return 'SUW';

  return null;
}

function normalize_hfa($status): ?string {
  $s = normalize_text($status);

  if (in_array($s, ['MODERATELY STUNTED', 'MST', 'STUNTED'], true)) return 'MSt';
  if (in_array($s, ['SEVERELY STUNTED', 'SST'], true)) return 'SSt';

  return null;
}

function normalize_wfl($status): ?string {
  $s = normalize_text($status);

  if (in_array($s, ['MODERATELY WASTED', 'MW', 'MAM', 'MW/MAM'], true)) return 'MW/MAM';
  if (in_array($s, ['SEVERELY WASTED', 'SW', 'SAM', 'SW/SAM'], true)) return 'SW/SAM';
  if (in_array($s, ['OVERWEIGHT', 'OW'], true)) return 'OW';
  if (in_array($s, ['OBESE', 'OB'], true)) return 'Ob';

  return null;
}

function normalize_muac($status): ?string {
  if ($status === null) return null;

  $s = normalize_text($status);
  if ($s === '') return null;

  if (in_array($s, ['MODERATELY WASTED', 'MW', 'MAM', 'MW/MAM'], true)) return 'MW/MAM';
  if (in_array($s, ['SEVERELY WASTED', 'SW', 'SAM', 'SW/SAM'], true)) return 'SW/SAM';

  return null;
}

function is_affected_row(?string $wfa, ?string $hfa, ?string $wfl, ?string $muac): bool {
  return $wfa !== null || $hfa !== null || $wfl !== null || $muac !== null;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = (int)($_GET['barangay_id'] ?? 0);
  $year = (int)($_GET['year'] ?? 0);
  $month = (int)($_GET['month'] ?? 0);

  if ($barangayId <= 0) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1c', null, 'Invalid barangay_id');
    json_out(422, ["ok" => false, "message" => "Invalid barangay"]);
  }

  if ($year < 2000 || $year > 2100) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1c', (string)$barangayId, 'Invalid year');
    json_out(422, ["ok" => false, "message" => "Invalid year"]);
  }

  if ($month < 1 || $month > 12) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1c', (string)$barangayId, 'Invalid month');
    json_out(422, ["ok" => false, "message" => "Invalid month"]);
  }

  if ($role !== 'admin') {
    $userBarangayStmt = $pdo->prepare("
      SELECT barangay_id
      FROM tbl_users
      WHERE users_id = ?
      LIMIT 1
    ");
    $userBarangayStmt->execute([$userId]);
    $userBarangayId = (int)($userBarangayStmt->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'opt_plus_form_1c', (string)$barangayId, 'No barangay assigned');
      json_out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }

    if ($userBarangayId !== $barangayId) {
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'opt_plus_form_1c', (string)$barangayId, 'User attempted access to another barangay report');
      json_out(403, ["ok" => false, "message" => "You are not allowed to access this barangay report"]);
    }
  }

  $barangayStmt = $pdo->prepare("
    SELECT 
      b.barangay_id,
      b.barangay_name,
      MAX(c.city_name) AS city_name,
      MAX(p.province_name) AS province_name
    FROM tbl_barangay b
    LEFT JOIN tbl_child_info ci ON ci.barangay_id = b.barangay_id
    LEFT JOIN tbl_city c ON c.city_id = ci.city_id
    LEFT JOIN tbl_province p ON p.province_id = ci.province_id
    WHERE b.barangay_id = ?
    GROUP BY b.barangay_id, b.barangay_name
    LIMIT 1
  ");
  $barangayStmt->execute([$barangayId]);
  $barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);

  if (!$barangay) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1c', (string)$barangayId, 'Barangay not found');
    json_out(404, ["ok" => false, "message" => "Barangay not found"]);
  }

  $startDate = sprintf('%04d-%02d-01', $year, $month);
  $nextMonthDate = date('Y-m-d', strtotime($startDate . ' +1 month'));

  $stmt = $pdo->prepare("
    SELECT
      ci.child_seq,
      ci.purok,
      ci.g_lastname,
      ci.g_firstname,
      ci.g_middlename,
      ci.c_lastname,
      ci.c_firstname,
      ci.c_middlename,
      ci.sex,
      ci.date_birth,

      m.measure_id,
      m.date_measured,
      m.age_months,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status

    FROM tbl_child_info ci
    INNER JOIN (
      SELECT child_seq, MAX(measure_id) AS latest_measure_id
      FROM tbl_measurement
      WHERE date_measured >= ? AND date_measured < ?
      GROUP BY child_seq
    ) latest
      ON latest.child_seq = ci.child_seq
    INNER JOIN tbl_measurement m
      ON m.measure_id = latest.latest_measure_id
    WHERE ci.barangay_id = ?
      AND TIMESTAMPDIFF(MONTH, ci.date_birth, m.date_measured) BETWEEN 0 AND 59
    ORDER BY
      COALESCE(ci.purok, '') ASC,
      ci.c_lastname ASC,
      ci.c_firstname ASC,
      ci.child_seq ASC
  ");
  $stmt->execute([$startDate, $nextMonthDate, $barangayId]);
  $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $rows = [];

  $countMUW = 0;
  $countSUW = 0;
  $countMSt = 0;
  $countSSt = 0;
  $countMWMAM = 0;
  $countSWSAM = 0;
  $countOW = 0;
  $countOb = 0;

  foreach ($rawRows as $row) {
    $wfa = normalize_wfa($row['weight_status'] ?? '');
    $hfa = normalize_hfa($row['height_status'] ?? '');
    $wfl = normalize_wfl($row['lt_status'] ?? '');
    $muac = normalize_muac($row['muac_status'] ?? null);

    if (!is_affected_row($wfa, $hfa, $wfl, $muac)) {
      continue;
    }

    if ($wfa === 'MUW') $countMUW++;
    if ($wfa === 'SUW') $countSUW++;
    if ($hfa === 'MSt') $countMSt++;
    if ($hfa === 'SSt') $countSSt++;
    if ($wfl === 'MW/MAM' || $muac === 'MW/MAM') $countMWMAM++;
    if ($wfl === 'SW/SAM' || $muac === 'SW/SAM') $countSWSAM++;
    if ($wfl === 'OW') $countOW++;
    if ($wfl === 'Ob') $countOb++;

    $rows[] = [
      'child_seq' => $row['child_seq'] ?? '',
      'address' => trim((string)($row['purok'] ?? '')),
      'caregiver' => clean_person_name(
        $row['g_lastname'] ?? '',
        $row['g_firstname'] ?? '',
        $row['g_middlename'] ?? ''
      ),
      'child_name' => clean_person_name(
        $row['c_lastname'] ?? '',
        $row['c_firstname'] ?? '',
        $row['c_middlename'] ?? ''
      ),
      'sex' => sex_value($row['sex'] ?? ''),
      'age_months' => $row['age_months'] !== null ? (int)$row['age_months'] : '',
      'wfa' => $wfa ?? '',
      'hfa' => $hfa ?? '',
      'wfl' => $wfl ?? '',
      'muac' => $muac ?? ''
    ];
  }

  $totalAffected = count($rows);

  $templatePath = __DIR__ . "/templates/opt_1c.xlsx";
  if (!file_exists($templatePath)) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1c', (string)$barangayId, 'Excel template not found');
    json_out(500, ["ok" => false, "message" => "Excel template not found"]);
  }

  $spreadsheet = IOFactory::load($templatePath);
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('OPT Plus Form 1C');

  // Header values
  $sheet->setCellValue('A3', 'YEAR:');
  $sheet->setCellValue('B3', $year);

  $sheet->setCellValue('A8', 'Barangay:');
  $sheet->setCellValue('C8', $barangay['barangay_name'] ?? '');

  $sheet->setCellValue('A9', 'Municipality:');
  $sheet->setCellValue('C9', $barangay['city_name'] ?? '');

  $sheet->setCellValue('B10', 'Province:');
  $sheet->setCellValue('C10', $barangay['province_name'] ?? '');

  $sheet->setCellValue('C6', $totalAffected);
  $sheet->setCellValue('E6', $countMUW);
  $sheet->setCellValue('E7', $countSUW);
  $sheet->setCellValue('G6', $countMSt);
  $sheet->setCellValue('G7', $countSSt);
  $sheet->setCellValue('I6', $countMWMAM);
  $sheet->setCellValue('I7', $countSWSAM);
  $sheet->setCellValue('I8', $countOW);
  $sheet->setCellValue('I9', $countOb);

  $undernutritionCount = $countMUW + $countSUW + $countMSt + $countSSt + $countMWMAM + $countSWSAM;
  $overweightObesityCount = $countOW + $countOb;

  $sheet->setCellValue('G10', $undernutritionCount);
  $sheet->setCellValue('G11', $overweightObesityCount);

  $startRow = 14;
  $templateRow = 14;
  $totalRows = count($rows);

  if ($totalRows > 1) {
    $sheet->insertNewRowBefore($startRow + 1, $totalRows - 1);

    for ($i = 1; $i < $totalRows; $i++) {
      $newRow = $startRow + $i;

      for ($col = 1; $col <= 10; $col++) {
        $columnLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->duplicateStyle(
          $sheet->getStyle($columnLetter . $templateRow),
          $columnLetter . $newRow
        );
      }

      $sheet->getRowDimension($newRow)->setRowHeight($sheet->getRowDimension($templateRow)->getRowHeight());
    }
  }

  if ($totalRows === 0) {
    $sheet->setCellValue('A14', '');
    $sheet->setCellValue('B14', 'No affected/at-risk children found for the selected barangay, month, and year.');
    $sheet->mergeCells('B14:J14');
    $sheet->getStyle('B14:J14')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
  } else {
    $rowNumber = $startRow;

    foreach ($rows as $row) {
      $sheet->setCellValue("A{$rowNumber}", $row['child_seq']);
      $sheet->setCellValue("B{$rowNumber}", $row['address']);
      $sheet->setCellValue("C{$rowNumber}", $row['caregiver']);
      $sheet->setCellValue("D{$rowNumber}", $row['child_name']);
      $sheet->setCellValue("E{$rowNumber}", $row['sex']);
      $sheet->setCellValue("F{$rowNumber}", $row['age_months']);
      $sheet->setCellValue("G{$rowNumber}", $row['wfa']);
      $sheet->setCellValue("H{$rowNumber}", $row['hfa']);
      $sheet->setCellValue("I{$rowNumber}", $row['wfl']);
      $sheet->setCellValue("J{$rowNumber}", $row['muac']);
      $rowNumber++;
    }
  }

  $filename = sprintf(
    'OPT_Plus_Form_1C_%s_%04d_%02d.xlsx',
    preg_replace('/[^A-Za-z0-9_\-]/', '_', $barangay['barangay_name']),
    $year,
    $month
  );

  audit_log(
    $pdo,
    $userId,
    'REPORT_EXPORTED',
    'opt_plus_form_1c',
    (string)$barangayId,
    "Exported OPT Plus Form 1C for barangay={$barangay['barangay_name']} ({$barangayId}), period={$year}-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ", rows={$totalRows}"
  );

  if (ob_get_length()) {
    ob_end_clean();
  }

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: max-age=0');
  header('Pragma: public');

  $writer = new Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;

} catch (Throwable $e) {
  if (isset($pdo) && isset($userId)) {
    audit_log(
      $pdo,
      $userId,
      'REPORT_EXPORT_FAILED',
      'opt_plus_form_1c',
      isset($barangayId) ? (string)$barangayId : null,
      $e->getMessage()
    );
  }

  json_out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}