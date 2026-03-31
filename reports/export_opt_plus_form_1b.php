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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function json_out($code, $payload) {
  if (ob_get_length()) ob_clean();
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function month_name_from_number($month) {
  $months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
  ];
  return $months[$month] ?? '';
}

function normalize_sex($value) {
  $v = strtolower(trim((string)$value));
  if ($v === 'm' || $v === 'male' || $v === 'boy') return 'M';
  if ($v === 'f' || $v === 'female' || $v === 'girl') return 'F';
  return '';
}

function normalize_status($value) {
  return strtolower(trim((string)$value));
}

function yes_like($value) {
  $v = strtolower(trim((string)$value));
  return in_array($v, ['1', 'y', 'yes', 'true'], true);
}

function age_band_index($ageMonths) {
  $age = (int)$ageMonths;
  if ($age < 0 || $age > 59) return null;
  if ($age <= 5) return 0;
  if ($age <= 11) return 1;
  if ($age <= 23) return 2;
  if ($age <= 35) return 3;
  if ($age <= 47) return 4;
  return 5;
}

function guardian_name_key(array $row) {
  $parts = [
    trim((string)($row['g_lastname'] ?? '')),
    trim((string)($row['g_firstname'] ?? '')),
    trim((string)($row['g_middlename'] ?? '')),
  ];
  $name = strtoupper(trim(implode(' ', array_filter($parts, fn($v) => $v !== ''))));
  return $name;
}

function child_name_key(array $row) {
  $parts = [
    trim((string)($row['c_lastname'] ?? '')),
    trim((string)($row['c_firstname'] ?? '')),
    trim((string)($row['c_middlename'] ?? '')),
  ];
  return strtoupper(trim(implode(' ', array_filter($parts, fn($v) => $v !== ''))));
}

function count_unique_guardians(array $rows, ?callable $filter = null) {
  $seen = [];
  foreach ($rows as $row) {
    if ($filter && !$filter($row)) {
      continue;
    }
    $key = guardian_name_key($row);
    if ($key === '') {
      $key = 'CHILD-' . (string)($row['child_seq'] ?? uniqid('', true));
    }
    $seen[$key] = true;
  }
  return count($seen);
}

function bump_bucket(array &$matrix, int $rowNum, ?int $bandIdx, string $sex, int $count = 1) {
  if ($bandIdx === null || !isset($matrix[$rowNum])) {
    return;
  }
  if ($sex === 'M') {
    $matrix[$rowNum][$bandIdx]['M'] += $count;
  } elseif ($sex === 'F') {
    $matrix[$rowNum][$bandIdx]['F'] += $count;
  }
}

function bucket_total(array $bucket) {
  return (int)$bucket['M'] + (int)$bucket['F'];
}

function write_band_row($sheet, int $rowNum, array $bands, bool $writeGrandTotal = true) {
  $startCols = ['B', 'E', 'H', 'K', 'N', 'Q'];
  foreach ($bands as $i => $bucket) {
    $boysCol = $startCols[$i];
    $girlsCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($boysCol) + 1);
    $totalCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($boysCol) + 2);

    $sheet->setCellValue($boysCol . $rowNum, (int)$bucket['M']);
    $sheet->setCellValue($girlsCol . $rowNum, (int)$bucket['F']);
    $sheet->setCellValue($totalCol . $rowNum, bucket_total($bucket));
  }

  if ($writeGrandTotal) {
    $grand = 0;
    foreach ($bands as $bucket) {
      $grand += bucket_total($bucket);
    }
    $sheet->setCellValue('T' . $rowNum, $grand);
  }
}

function count_if(array $rows, callable $filter) {
  $n = 0;
  foreach ($rows as $row) {
    if ($filter($row)) $n++;
  }
  return $n;
}

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = (int)($_GET['barangay_id'] ?? 0);
  $year = (int)($_GET['year'] ?? 0);
  $month = (int)($_GET['month'] ?? 0);

  if ($barangayId <= 0) {
    json_out(422, ["ok" => false, "message" => "Invalid barangay"]);
  }
  if ($year < 2000 || $year > 2100) {
    json_out(422, ["ok" => false, "message" => "Invalid year"]);
  }
  if ($month < 1 || $month > 12) {
    json_out(422, ["ok" => false, "message" => "Invalid month"]);
  }

  if ($role !== 'admin') {
    $userBarangayStmt = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
    $userBarangayStmt->execute([$userId]);
    $userBarangayId = (int)($userBarangayStmt->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      json_out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }
    if ($userBarangayId !== $barangayId) {
      json_out(403, ["ok" => false, "message" => "You are not allowed to access this barangay report"]);
    }
  }

  $barangayStmt = $pdo->prepare("\n    SELECT barangay_id, barangay_name, COALESCE(barangay_code, brgy_code, '') AS barangay_code\n    FROM tbl_barangay\n    WHERE barangay_id = ?\n    LIMIT 1\n  ");
  $barangayStmt->execute([$barangayId]);
  $barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);

  if (!$barangay) {
    json_out(404, ["ok" => false, "message" => "Barangay not found"]);
  }

  $templatePath = __DIR__ . "/templates/opt_1b.xlsx";
  if (!file_exists($templatePath)) {
    json_out(500, ["ok" => false, "message" => "Excel template not found"]);
  }

  $startDate = sprintf('%04d-%02d-01', $year, $month);
  $nextMonthDate = date('Y-m-d', strtotime($startDate . ' +1 month'));

  $stmt = $pdo->prepare("\n    SELECT
      ci.child_seq,
      ci.purok,
      ci.ip_group,
      ci.sex,
      ci.date_birth,
      ci.disability,
      ci.g_lastname,
      ci.g_firstname,
      ci.g_middlename,
      ci.c_lastname,
      ci.c_firstname,
      ci.c_middlename,
      m.measure_id,
      m.date_measured,
      m.age_months,
      m.weight,
      m.height,
      m.muac,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.bilateral_pitting
    FROM tbl_child_info ci
    INNER JOIN (
      SELECT t.child_seq, MAX(t.measure_id) AS latest_measure_id
      FROM tbl_measurement t
      WHERE t.date_measured >= ? AND t.date_measured < ?
      GROUP BY t.child_seq
    ) latest ON latest.child_seq = ci.child_seq
    INNER JOIN tbl_measurement m ON m.measure_id = latest.latest_measure_id
    WHERE ci.barangay_id = ?
    ORDER BY ci.child_seq ASC
  ");
  $stmt->execute([$startDate, $nextMonthDate, $barangayId]);
  $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $rows = [];
  $olderThan59 = 0;
  foreach ($allRows as $row) {
    $ageMonths = $row['age_months'] !== null && $row['age_months'] !== ''
      ? (int)$row['age_months']
      : (($row['date_birth'] && $row['date_measured'])
          ? max(0, (int)((new DateTime($row['date_birth']))->diff(new DateTime($row['date_measured']))->y * 12 + (new DateTime($row['date_birth']))->diff(new DateTime($row['date_measured']))->m))
          : null);

    $row['age_months'] = $ageMonths;

    if ($ageMonths === null || $ageMonths > 59) {
      $olderThan59++;
      continue;
    }
    if ($ageMonths < 0) {
      continue;
    }
    $rows[] = $row;
  }

  $spreadsheet = IOFactory::load($templatePath);
  $spreadsheet->setActiveSheetIndex(0);
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('OPT Plus Form 1B');

  // Header mapping aligned to the actual template.
  $sheet->setCellValueExplicit('H9', 'Misamis Occidental', DataType::TYPE_STRING);
  $sheet->setCellValueExplicit('B10', (string)$barangay['barangay_name'], DataType::TYPE_STRING);
  $sheet->setCellValueExplicit('B11', 'Tangub City', DataType::TYPE_STRING);
  $sheet->setCellValueExplicit('B12', (string)($barangay['barangay_code'] ?? ''), DataType::TYPE_STRING);

  // Values not yet available from the current schema: keep blank instead of wrong numbers.
  $sheet->setCellValue('J10', '');
  $sheet->setCellValue('J11', '');
  $sheet->setCellValue('P11', '');
  $sheet->setCellValue('Q9', '');
  $sheet->setCellValue('R11', month_name_from_number($month) . ' ' . $year);

  $ipMeasured = count_if($rows, fn($r) => yes_like($r['ip_group'] ?? ''));
  $sheet->setCellValue('T10', $ipMeasured);

  // Matrix rows in the template.
  $matrixRows = [17, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 34];
  $matrix = [];
  foreach ($matrixRows as $r) {
    $matrix[$r] = [];
    for ($i = 0; $i < 6; $i++) {
      $matrix[$r][$i] = ['M' => 0, 'F' => 0];
    }
  }

  $muacMatrix = [31 => [], 32 => [], 33 => []];
  foreach ([31, 32, 33] as $r) {
    for ($i = 0; $i < 6; $i++) {
      $muacMatrix[$r][$i] = ['M' => 0, 'F' => 0];
    }
  }

  foreach ($rows as $row) {
    $bandIdx = age_band_index($row['age_months']);
    $sex = normalize_sex($row['sex'] ?? '');
    if ($bandIdx === null || $sex === '') {
      continue;
    }

    $weightStatus = normalize_status($row['weight_status'] ?? '');
    $heightStatus = normalize_status($row['height_status'] ?? '');
    $ltStatus = normalize_status($row['lt_status'] ?? '');
    $muacStatus = normalize_status($row['muac_status'] ?? '');

    if ($weightStatus !== '') {
      bump_bucket($matrix, 34, $bandIdx, $sex);
    }

    if ($weightStatus === 'normal') bump_bucket($matrix, 17, $bandIdx, $sex);
    if ($weightStatus === 'underweight') bump_bucket($matrix, 20, $bandIdx, $sex);
    if ($weightStatus === 'severely underweight') bump_bucket($matrix, 21, $bandIdx, $sex);

    if ($heightStatus === 'normal') bump_bucket($matrix, 22, $bandIdx, $sex);
    if ($heightStatus === 'tall') bump_bucket($matrix, 23, $bandIdx, $sex);
    if ($heightStatus === 'stunted') bump_bucket($matrix, 24, $bandIdx, $sex);
    if ($heightStatus === 'severely stunted') bump_bucket($matrix, 25, $bandIdx, $sex);

    if ($ltStatus === 'normal') bump_bucket($matrix, 26, $bandIdx, $sex);
    if ($ltStatus === 'overweight') bump_bucket($matrix, 27, $bandIdx, $sex);
    if ($ltStatus === 'obese') bump_bucket($matrix, 28, $bandIdx, $sex);
    if ($ltStatus === 'wasted') bump_bucket($matrix, 29, $bandIdx, $sex);
    if ($ltStatus === 'severely wasted') bump_bucket($matrix, 30, $bandIdx, $sex);

    // MUAC block starts at 6-11 months in the template, so skip age band 0-5 months.
    if ($bandIdx >= 1) {
      if ($muacStatus === 'normal') bump_bucket($muacMatrix, 31, $bandIdx, $sex);
      if ($muacStatus === 'mam') bump_bucket($muacMatrix, 32, $bandIdx, $sex);
      if ($muacStatus === 'sam') bump_bucket($muacMatrix, 33, $bandIdx, $sex);
    }
  }

  foreach ([17, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 34] as $rowNum) {
    write_band_row($sheet, $rowNum, $matrix[$rowNum], true);
  }

  // MUAC rows use E:R only in the template and no grand total cell.
  foreach ([31, 32, 33] as $rowNum) {
    $bandsForMuac = array_slice($muacMatrix[$rowNum], 1); // 6-11 up to 48-59
    $startCols = ['E', 'H', 'K', 'N', 'Q'];
    foreach ($bandsForMuac as $i => $bucket) {
      $boysCol = $startCols[$i];
      $girlsCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($boysCol) + 1);
      $totalCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($boysCol) + 2);
      $sheet->setCellValue($boysCol . $rowNum, (int)$bucket['M']);
      $sheet->setCellValue($girlsCol . $rowNum, (int)$bucket['F']);
      $sheet->setCellValue($totalCol . $rowNum, bucket_total($bucket));
    }
    $sheet->setCellValue('T' . $rowNum, '');
  }

  $totalBoys = count_if($rows, fn($r) => normalize_sex($r['sex'] ?? '') === 'M');
  $totalGirls = count_if($rows, fn($r) => normalize_sex($r['sex'] ?? '') === 'F');
  $totalMuac = count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 6 && normalize_status($r['muac_status'] ?? '') !== '');
  $totalWfa = count_if($rows, fn($r) => normalize_status($r['weight_status'] ?? '') !== '');
  $totalHfa = count_if($rows, fn($r) => normalize_status($r['height_status'] ?? '') !== '');
  $totalWflh = count_if($rows, fn($r) => normalize_status($r['lt_status'] ?? '') !== '');

  $sheet->setCellValue('D14', $totalBoys);
  $sheet->setCellValue('G14', $totalGirls);
  $sheet->setCellValue('J14', $totalMuac);
  $sheet->setCellValue('M14', $totalWfa);
  $sheet->setCellValue('P14', $totalHfa);
  $sheet->setCellValue('S14', $totalWflh);

  $isWastedOrStunted = function(array $r): bool {
    $lt = normalize_status($r['lt_status'] ?? '');
    $h = normalize_status($r['height_status'] ?? '');
    return in_array($lt, ['wasted', 'severely wasted'], true)
      || in_array($h, ['stunted', 'severely stunted'], true);
  };

  $isOverweightObese = function(array $r): bool {
    $lt = normalize_status($r['lt_status'] ?? '');
    return in_array($lt, ['overweight', 'obese'], true);
  };

  $sheet->setCellValue('H36', count_if($rows, $isWastedOrStunted));
  $sheet->setCellValue('H37', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 24 && ((int)($r['age_months'] ?? -1)) <= 59 && $isWastedOrStunted($r)));
  $sheet->setCellValue('H38', count_if($rows, $isOverweightObese));
  $sheet->setCellValue('H39', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 0 && ((int)($r['age_months'] ?? -1)) <= 23));
  $sheet->setCellValue('H40', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 0 && ((int)($r['age_months'] ?? -1)) <= 23 && $isWastedOrStunted($r)));
  $sheet->setCellValue('H41', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 0 && ((int)($r['age_months'] ?? -1)) <= 29));
  $sheet->setCellValue('H42', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 30 && ((int)($r['age_months'] ?? -1)) <= 59));
  $sheet->setCellValue('H43', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 24 && ((int)($r['age_months'] ?? -1)) <= 59));
  $sheet->setCellValue('H44', count_if($rows, fn($r) => yes_like($r['bilateral_pitting'] ?? '')));
  $sheet->setCellValue('H45', count_if($rows, fn($r) => yes_like($r['disability'] ?? '')));

  $sheet->setCellValue('S36', count_unique_guardians($rows));
  $sheet->setCellValue('S37', count_unique_guardians($rows, $isWastedOrStunted));
  $sheet->setCellValue('S38', count_unique_guardians($rows, $isOverweightObese));
  $sheet->setCellValue('S39', count_unique_guardians($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 0 && ((int)($r['age_months'] ?? -1)) <= 23));
  $sheet->setCellValue('S40', count_unique_guardians($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 0 && ((int)($r['age_months'] ?? -1)) <= 23 && $isWastedOrStunted($r)));

  $duplicateMap = [];
  foreach ($rows as $row) {
    $childKey = child_name_key($row);
    $dob = trim((string)($row['date_birth'] ?? ''));
    if ($childKey === '' || $dob === '') continue;
    $key = $childKey . '|' . $dob;
    $duplicateMap[$key] = ($duplicateMap[$key] ?? 0) + 1;
  }
  $duplicateCount = 0;
  foreach ($duplicateMap as $cnt) {
    if ($cnt > 1) $duplicateCount += $cnt;
  }

  $missingInfo = count_if($rows, function($r) {
    return trim((string)($r['c_lastname'] ?? '')) === ''
      || trim((string)($r['c_firstname'] ?? '')) === ''
      || trim((string)($r['sex'] ?? '')) === ''
      || trim((string)($r['date_birth'] ?? '')) === ''
      || trim((string)($r['purok'] ?? '')) === '';
  });

  $missingParentOrAddress = count_if($rows, function($r) {
    $guardianMissing = trim((string)($r['g_lastname'] ?? '')) === ''
      && trim((string)($r['g_firstname'] ?? '')) === ''
      && trim((string)($r['g_middlename'] ?? '')) === '';
    $addressMissing = trim((string)($r['purok'] ?? '')) === '';
    return $guardianMissing || $addressMissing;
  });

  $sheet->setCellValue('T36', $duplicateCount);
  $sheet->setCellValue('T37', $missingInfo);
  $sheet->setCellValue('T38', $missingParentOrAddress);
  $sheet->setCellValue('T39', count_if($rows, fn($r) => normalize_sex($r['sex'] ?? '') === ''));
  $sheet->setCellValue('T40', count_if($rows, fn($r) => trim((string)($r['date_birth'] ?? '')) === ''));
  $sheet->setCellValue('T41', $olderThan59);
  $sheet->setCellValue('T42', count_if($rows, fn($r) => (trim((string)($r['height'] ?? '')) !== '') && (trim((string)($r['weight'] ?? '')) === '')));
  $sheet->setCellValue('T43', count_if($rows, fn($r) => (trim((string)($r['weight'] ?? '')) !== '') && (trim((string)($r['height'] ?? '')) === '')));
  $sheet->setCellValue('T44', count_if($rows, fn($r) => ((int)($r['age_months'] ?? -1)) >= 6 && trim((string)($r['muac'] ?? '')) === ''));
  $sheet->setCellValue('T45', count_if($rows, fn($r) => trim((string)($r['bilateral_pitting'] ?? '')) === ''));

  $filename = sprintf(
    'OPT_Plus_Form_1B_%s_%04d_%02d.xlsx',
    preg_replace('/[^A-Za-z0-9_\-]/', '_', $barangay['barangay_name']),
    $year,
    $month
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
  json_out(500, [
    "ok" => false,
    "message" => "Server error",
    "error" => $e->getMessage()
  ]);
}
