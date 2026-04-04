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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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

function clean_person_name($last, $first, $middle = '') {
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

function month_name_from_number($month) {
  $months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
  ];

  return $months[$month] ?? '';
}

function yes_no_value($value) {
  $v = strtoupper(trim((string)$value));
  if ($v === '' || $v === 'NO' || $v === 'N' || $v === '0') {
    return 'NO';
  }
  return 'YES';
}

function safe_date($value) {
  if (!$value) return '';
  $ts = strtotime((string)$value);
  if (!$ts) return '';
  return date('m/d/Y', $ts);
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = (int)($_GET['barangay_id'] ?? 0);
  $year = (int)($_GET['year'] ?? 0);
  $month = (int)($_GET['month'] ?? 0);

  if ($barangayId <= 0) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1a', null, 'Invalid barangay_id');
    json_out(422, ["ok" => false, "message" => "Invalid barangay"]);
  }

  if ($year < 2000 || $year > 2100) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1a', (string)$barangayId, 'Invalid year');
    json_out(422, ["ok" => false, "message" => "Invalid year"]);
  }

  if ($month < 1 || $month > 12) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1a', (string)$barangayId, 'Invalid month');
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
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'opt_plus_form_1a', (string)$barangayId, 'No barangay assigned');
      json_out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }

    if ($userBarangayId !== $barangayId) {
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'opt_plus_form_1a', (string)$barangayId, 'User attempted access to another barangay report');
      json_out(403, ["ok" => false, "message" => "You are not allowed to access this barangay report"]);
    }
  }

  $barangayStmt = $pdo->prepare("
    SELECT barangay_id, barangay_name
    FROM tbl_barangay
    WHERE barangay_id = ?
    LIMIT 1
  ");
  $barangayStmt->execute([$barangayId]);
  $barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);

  if (!$barangay) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1a', (string)$barangayId, 'Barangay not found');
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
      ci.ip_group,
      ci.sex,
      ci.date_birth,
      ci.disability,

      m.measure_id,
      m.date_measured,
      m.weight,
      m.height,
      m.muac,
      m.age_months,
      m.lt_status,
      m.bilateral_pitting

    FROM tbl_child_info ci
    INNER JOIN (
      SELECT t.child_seq, MAX(t.measure_id) AS latest_measure_id
      FROM tbl_measurement t
      WHERE t.date_measured >= ? AND t.date_measured < ?
      GROUP BY t.child_seq
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
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $templatePath = __DIR__ . "/templates/opt_1a_single.xlsx";
  if (!file_exists($templatePath)) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_1a', (string)$barangayId, 'Excel template not found');
    json_out(500, ["ok" => false, "message" => "Excel template not found"]);
  }

  $spreadsheet = IOFactory::load($templatePath);
  $sheet = $spreadsheet->getActiveSheet();

  $sheet->setTitle('OPT Plus Form 1A');
  $sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

  $sheet->setCellValue('D1', $barangay['barangay_name']);
  $sheet->setCellValue('G1', 'Tangub City');
  $sheet->setCellValue('G2', 'Misamis Occidental');
  $sheet->setCellValue('L1', $year);
  $sheet->setCellValue('P5', date('m/d/Y'));
  $sheet->setCellValue('K6', month_name_from_number($month));

  $templateRow = 8;
  $startRow = 8;
  $totalRows = count($rows);

  if ($totalRows > 1) {
    $sheet->insertNewRowBefore($startRow + 1, $totalRows - 1);

    for ($i = 1; $i < $totalRows; $i++) {
      $newRow = $startRow + $i;

      for ($col = 1; $col <= 16; $col++) {
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
    $sheet->setCellValue('A8', '');
    $sheet->setCellValue('B8', 'No records found for the selected barangay, month, and year.');
    $sheet->mergeCells('B8:P8');
    $sheet->getStyle('B8:P8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
  } else {
    $rowNumber = $startRow;

    foreach ($rows as $row) {
      $sheet->setCellValue("A{$rowNumber}", $row['child_seq'] ?? '');
      $sheet->setCellValue("B{$rowNumber}", trim((string)($row['purok'] ?? '')));
      $sheet->setCellValue("C{$rowNumber}", clean_person_name(
        $row['g_lastname'] ?? '',
        $row['g_firstname'] ?? '',
        $row['g_middlename'] ?? ''
      ));
      $sheet->setCellValue("D{$rowNumber}", clean_person_name(
        $row['c_lastname'] ?? '',
        $row['c_firstname'] ?? '',
        $row['c_middlename'] ?? ''
      ));
      $sheet->setCellValue("E{$rowNumber}", yes_no_value($row['ip_group'] ?? ''));
      $sheet->setCellValue("F{$rowNumber}", strtoupper(trim((string)($row['sex'] ?? ''))));
      $sheet->setCellValue("G{$rowNumber}", safe_date($row['date_birth'] ?? ''));
      $sheet->setCellValue("H{$rowNumber}", safe_date($row['date_measured'] ?? ''));
      $sheet->setCellValue("I{$rowNumber}", $row['weight'] !== null ? (float)$row['weight'] : '');
      $sheet->setCellValue("J{$rowNumber}", $row['height'] !== null ? (float)$row['height'] : '');
      $sheet->setCellValue("K{$rowNumber}", $row['age_months'] !== null ? (int)$row['age_months'] : '');
      $sheet->setCellValue("L{$rowNumber}", trim((string)($row['lt_status'] ?? '')));
      $sheet->setCellValue("M{$rowNumber}", $row['muac'] !== null ? (float)$row['muac'] : '');
      $sheet->setCellValue("N{$rowNumber}", trim((string)($row['bilateral_pitting'] ?? '')));
      $sheet->setCellValue("O{$rowNumber}", yes_no_value($row['disability'] ?? ''));
      $sheet->setCellValue("P{$rowNumber}", '');

      $rowNumber++;
    }
  }

  $filename = sprintf(
    'OPT_Plus_Form_1A_%s_%04d_%02d.xlsx',
    preg_replace('/[^A-Za-z0-9_\-]/', '_', $barangay['barangay_name']),
    $year,
    $month
  );

  audit_log(
    $pdo,
    $userId,
    'REPORT_EXPORTED',
    'opt_plus_form_1a',
    (string)$barangayId,
    "Exported OPT Plus Form 1A for barangay={$barangay['barangay_name']} ({$barangayId}), period={$year}-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ", rows={$totalRows}"
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
      'opt_plus_form_1a',
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