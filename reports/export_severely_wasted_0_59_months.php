<?php

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
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

function mark_measurements_as_exported(PDO $pdo, array $measureIds): int {
  $measureIds = array_values(array_unique(array_filter(array_map('intval', $measureIds))));
  if (empty($measureIds)) return 0;

  $placeholders = implode(',', array_fill(0, count($measureIds), '?'));

  $sql = "
    UPDATE tbl_measurement
    SET
      is_exported_excel = 1,
      excel_exported_at = COALESCE(excel_exported_at, NOW())
    WHERE measure_id IN ($placeholders)
  ";

  $st = $pdo->prepare($sql);
  $st->execute($measureIds);

  return $st->rowCount();
}

function apply_data_row_style($sheet, int $rowNumber): void {
  $sheet->getRowDimension($rowNumber)->setRowHeight(24);

  $sheet->getStyle("A{$rowNumber}:O{$rowNumber}")->applyFromArray([
    'borders' => [
      'allBorders' => [
        'borderStyle' => Border::BORDER_THIN,
        'color' => ['rgb' => '000000']
      ]
    ],
    'alignment' => [
      'vertical' => Alignment::VERTICAL_CENTER
    ]
  ]);

  $sheet->getStyle("A{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
  $sheet->getStyle("B{$rowNumber}:D{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
  $sheet->getStyle("B{$rowNumber}:D{$rowNumber}")->getAlignment()->setWrapText(true);
  $sheet->getStyle("E{$rowNumber}:O{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = (int)($_GET['barangay_id'] ?? 0);
  $year = (int)($_GET['year'] ?? 0);
  $month = (int)($_GET['month'] ?? 0);

  if ($barangayId <= 0) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'severely_wasted_0_59_months', null, 'Invalid barangay_id');
    json_out(422, ["ok" => false, "message" => "Invalid barangay"]);
  }

  if ($year < 2000 || $year > 2100) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'severely_wasted_0_59_months', (string)$barangayId, 'Invalid year');
    json_out(422, ["ok" => false, "message" => "Invalid year"]);
  }

  if ($month < 1 || $month > 12) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'severely_wasted_0_59_months', (string)$barangayId, 'Invalid month');
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
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'severely_wasted_0_59_months', (string)$barangayId, 'No barangay assigned');
      json_out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }

    if ($userBarangayId !== $barangayId) {
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'severely_wasted_0_59_months', (string)$barangayId, 'User attempted access to another barangay report');
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
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'severely_wasted_0_59_months', (string)$barangayId, 'Barangay not found');
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
      m.height,
      m.weight,
      m.muac,
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
      IFNULL(ci.purok, '') ASC,
      ci.c_lastname ASC,
      ci.c_firstname ASC,
      ci.child_seq ASC
  ");
  $stmt->execute([$startDate, $nextMonthDate, $barangayId]);
  $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $rows = [];

  foreach ($rawRows as $row) {
    $wfl = normalize_wfl($row['lt_status'] ?? '');
    $muacStatus = normalize_muac($row['muac_status'] ?? null);

    if ($wfl !== 'SW/SAM' && $muacStatus !== 'SW/SAM') {
      continue;
    }

    $rows[] = [
      'measure_id' => isset($row['measure_id']) ? (int)$row['measure_id'] : 0,
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
      'birthdate' => $row['date_birth'] ?? null,
      'height_value' => $row['height'] !== null && $row['height'] !== '' ? (float)$row['height'] : '',
      'weight_value' => $row['weight'] !== null && $row['weight'] !== '' ? (float)$row['weight'] : '',
      'muac_value' => $row['muac'] !== null && $row['muac'] !== '' ? (float)$row['muac'] : ''
    ];
  }

  $totalChildren = count($rows);
  $measureIds = array_values(array_filter(array_map(
    fn($r) => isset($r['measure_id']) ? (int)$r['measure_id'] : 0,
    $rows
  )));

  $templatePath = __DIR__ . "/templates/list_of_sam.xlsx";
  if (!file_exists($templatePath)) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'severely_wasted_0_59_months', (string)$barangayId, 'Excel template not found');
    json_out(500, ["ok" => false, "message" => "Excel template not found"]);
  }

  $spreadsheet = IOFactory::load($templatePath);
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('Severely Wasted');

  // These cell positions follow the same layout pattern used for the MAM export.
  // If your SAM template has different header cells, only adjust these references.
  $baseTemplateRows = 14;
  $startRow = 11;
  $pageCount = max(1, (int)ceil(max($totalChildren, 1) / $baseTemplateRows));

  $sheet->setCellValue('M6', '');
  $sheet->setCellValue('N6', '');
  $sheet->setCellValue('O8', $pageCount);

  $sheet->setCellValue('F6', $barangay['barangay_name'] ?? '');
  $sheet->setCellValue('K6', $barangay['city_name'] ?? '');
  $sheet->setCellValue('C7', $year);
  $sheet->setCellValue('H8', $totalChildren);

  if ($totalChildren > $baseTemplateRows) {
    $sheet->insertNewRowBefore(25, $totalChildren - $baseTemplateRows);
  }

  if ($totalChildren === 0) {
    $sheet->mergeCells("A{$startRow}:O{$startRow}");
    $sheet->setCellValue("A{$startRow}", 'No severely wasted (SAM) children found for the selected barangay, month, and year.');
    $sheet->getStyle("A{$startRow}:O{$startRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A{$startRow}:O{$startRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A{$startRow}:O{$startRow}")->getAlignment()->setWrapText(true);
    apply_data_row_style($sheet, $startRow);
  } else {
    $rowNumber = $startRow;

    foreach ($rows as $row) {
      $sheet->setCellValue("A{$rowNumber}", $row['child_seq']);
      $sheet->setCellValue("B{$rowNumber}", $row['address']);
      $sheet->setCellValue("C{$rowNumber}", $row['caregiver']);
      $sheet->setCellValue("D{$rowNumber}", $row['child_name']);
      $sheet->setCellValue("E{$rowNumber}", $row['sex']);

      if (!empty($row['birthdate'])) {
        $sheet->setCellValue("F{$rowNumber}", ExcelDate::PHPToExcel(strtotime($row['birthdate'])));
        $sheet->getStyle("F{$rowNumber}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
      } else {
        $sheet->setCellValue("F{$rowNumber}", '');
      }

      $sheet->setCellValue("G{$rowNumber}", $row['height_value']);
      $sheet->setCellValue("H{$rowNumber}", $row['weight_value']);
      $sheet->setCellValue("I{$rowNumber}", $row['muac_value']);

      // Follow-up columns left blank for manual input
      $sheet->setCellValue("J{$rowNumber}", '');
      $sheet->setCellValue("K{$rowNumber}", '');
      $sheet->setCellValue("L{$rowNumber}", '');
      $sheet->setCellValue("M{$rowNumber}", '');
      $sheet->setCellValue("N{$rowNumber}", '');
      $sheet->setCellValue("O{$rowNumber}", '');

      apply_data_row_style($sheet, $rowNumber);
      $rowNumber++;
    }

    $sheet->getStyle("G{$startRow}:I" . ($startRow + $totalChildren - 1))
      ->getNumberFormat()
      ->setFormatCode('0.00');
  }

  $filename = sprintf(
    'Severely_Wasted_SAM_Children_0_59_Months_%s_%04d_%02d.xlsx',
    preg_replace('/[^A-Za-z0-9_\-]/', '_', $barangay['barangay_name']),
    $year,
    $month
  );

  $exportedCount = mark_measurements_as_exported($pdo, $measureIds);

  audit_log(
    $pdo,
    $userId,
    'REPORT_EXPORTED',
    'severely_wasted_0_59_months',
    (string)$barangayId,
    "Exported Severely Wasted (SAM) 0-59 Months for barangay={$barangay['barangay_name']} ({$barangayId}), period={$year}-" .
    str_pad((string)$month, 2, '0', STR_PAD_LEFT) .
    ", rows={$totalChildren}, measurements_marked={$exportedCount}"
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
      'severely_wasted_0_59_months',
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