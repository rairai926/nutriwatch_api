<?php

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

use PhpOffice\PhpSpreadsheet\IOFactory;
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

function normalize_text($value): string {
  $value = strtoupper(trim((string)$value));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value;
}

function sex_key($sex): ?string {
  $s = normalize_text($sex);
  if (in_array($s, ['M', 'MALE', 'BOY', 'BOYS'], true)) return 'boys';
  if (in_array($s, ['F', 'FEMALE', 'GIRL', 'GIRLS'], true)) return 'girls';
  return null;
}

function age_bucket($ageMonths): ?string {
  $age = (int)$ageMonths;
  if ($age >= 0 && $age <= 5) return '0_5';
  if ($age >= 6 && $age <= 11) return '6_11';
  if ($age >= 12 && $age <= 23) return '12_23';
  if ($age >= 24 && $age <= 35) return '24_35';
  if ($age >= 36 && $age <= 47) return '36_47';
  if ($age >= 48 && $age <= 59) return '48_59';
  return null;
}

function status_group_wfa($status): ?string {
  $s = normalize_text($status);

  if (in_array($s, ['NORMAL', 'N'], true)) return 'normal';
  if (in_array($s, ['MODERATELY UNDERWEIGHT', 'MUW', 'UNDERWEIGHT', 'UW'], true)) return 'muw';
  if (in_array($s, ['SEVERELY UNDERWEIGHT', 'SUW'], true)) return 'suw';

  return null;
}

function status_group_hfa($status): ?string {
  $s = normalize_text($status);

  if (in_array($s, ['NORMAL', 'N'], true)) return 'normal';
  if (in_array($s, ['TALL'], true)) return 'tall';
  if (in_array($s, ['MODERATELY STUNTED', 'MST', 'STUNTED'], true)) return 'mst';
  if (in_array($s, ['SEVERELY STUNTED', 'SST'], true)) return 'sst';

  return null;
}

function status_group_wfl($status): ?string {
  $s = normalize_text($status);

  if (in_array($s, ['NORMAL', 'N'], true)) return 'normal';
  if (in_array($s, ['OVERWEIGHT', 'OW'], true)) return 'ow';
  if (in_array($s, ['OBESE', 'OB'], true)) return 'ob';
  if (in_array($s, ['MODERATELY WASTED', 'MW', 'MAM', 'MW/MAM'], true)) return 'mw_mam';
  if (in_array($s, ['SEVERELY WASTED', 'SW', 'SAM', 'SW/SAM'], true)) return 'sw_sam';

  return null;
}

function status_group_muac($status): ?string {
  $s = normalize_text($status);
  if ($s === '') return null;

  if (in_array($s, ['NORMAL', 'N'], true)) return 'normal';
  if (in_array($s, ['MODERATELY WASTED', 'MW', 'MAM', 'MW/MAM'], true)) return 'mw_mam';
  if (in_array($s, ['SEVERELY WASTED', 'SW', 'SAM', 'SW/SAM'], true)) return 'sw_sam';

  return null;
}

function is_yes($value): bool {
  $v = normalize_text($value);
  return in_array($v, ['YES', 'Y', '1', 'TRUE'], true);
}

function make_zero_buckets(): array {
  $ages = ['0_5', '6_11', '12_23', '24_35', '36_47', '48_59'];
  $out = [];
  foreach ($ages as $age) {
    $out[$age] = [
      'boys' => 0,
      'girls' => 0,
      'total' => 0
    ];
  }
  return $out;
}

function init_status_matrix(array $keys): array {
  $out = [];
  foreach ($keys as $k) {
    $out[$k] = make_zero_buckets();
  }
  return $out;
}

function increment_matrix(array &$matrix, string $statusKey, string $ageKey, string $sexKey): void {
  if (!isset($matrix[$statusKey][$ageKey][$sexKey])) return;
  $matrix[$statusKey][$ageKey][$sexKey]++;
  $matrix[$statusKey][$ageKey]['total']++;
}

function sum_status_total(array $matrix, string $statusKey): int {
  $sum = 0;
  foreach ($matrix[$statusKey] as $ageGroup) {
    $sum += (int)($ageGroup['total'] ?? 0);
  }
  return $sum;
}

function sum_status_0_23(array $matrix, string $statusKey): int {
  return
    (int)($matrix[$statusKey]['0_5']['total'] ?? 0) +
    (int)($matrix[$statusKey]['6_11']['total'] ?? 0) +
    (int)($matrix[$statusKey]['12_23']['total'] ?? 0);
}

function prevalence(int $num, int $den): string {
  if ($den <= 0) return '0';
  return number_format(($num / $den) * 100, 2);
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

try {
  $authUser = authenticate(['admin', 'user', 'bns']);
  $role = strtolower($authUser->role ?? 'user');
  $userId = (int)($authUser->sub ?? 0);

  $barangayId = (int)($_GET['barangay_id'] ?? 0);
  $year = (int)($_GET['year'] ?? 0);
  $month = (int)($_GET['month'] ?? 0);

  if ($barangayId <= 0) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_b', null, 'Invalid barangay_id');
    json_out(422, ["ok" => false, "message" => "Invalid barangay"]);
  }

  if ($year < 2000 || $year > 2100) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_b', (string)$barangayId, 'Invalid year');
    json_out(422, ["ok" => false, "message" => "Invalid year"]);
  }

  if ($month < 1 || $month > 12) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_b', (string)$barangayId, 'Invalid month');
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
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'opt_plus_form_b', (string)$barangayId, 'No barangay assigned');
      json_out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }

    if ($userBarangayId !== $barangayId) {
      audit_log($pdo, $userId, 'REPORT_EXPORT_DENIED', 'opt_plus_form_b', (string)$barangayId, 'User attempted access to another barangay report');
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
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_b', (string)$barangayId, 'Barangay not found');
    json_out(404, ["ok" => false, "message" => "Barangay not found"]);
  }

  $startDate = sprintf('%04d-%02d-01', $year, $month);
  $nextMonthDate = date('Y-m-d', strtotime($startDate . ' +1 month'));

  $stmt = $pdo->prepare("
    SELECT
      ci.child_seq,
      ci.barangay_id,
      ci.ip_group,
      ci.sex,
      ci.date_birth,
      ci.g_lastname,
      ci.g_firstname,
      ci.g_middlename,
      ci.c_lastname,
      ci.c_firstname,
      ci.c_middlename,
      ci.purok,

      m.measure_id,
      m.date_measured,
      m.age_months,
      m.weight_status,
      m.height_status,
      m.lt_status,
      m.muac_status,
      m.bilateral_pitting

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
    ORDER BY ci.child_seq ASC
  ");
  $stmt->execute([$startDate, $nextMonthDate, $barangayId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $measureIds = array_values(array_filter(array_map(
  fn($r) => isset($r['measure_id']) ? (int)$r['measure_id'] : 0,
  $rows
  )));

  $templatePath = __DIR__ . "/templates/opt_1b.xlsx";
  if (!file_exists($templatePath)) {
    audit_log($pdo, $userId, 'REPORT_EXPORT_FAILED', 'opt_plus_form_b', (string)$barangayId, 'Excel template not found');
    json_out(500, ["ok" => false, "message" => "Excel template not found"]);
  }

  $wfa = init_status_matrix(['normal', 'muw', 'suw']);
  $hfa = init_status_matrix(['normal', 'tall', 'mst', 'sst']);
  $wfl = init_status_matrix(['normal', 'ow', 'ob', 'mw_mam', 'sw_sam']);
  $muac = init_status_matrix(['normal', 'mw_mam', 'sw_sam']);

  $totalBoys = 0;
  $totalGirls = 0;
  $totalChildren = 0;

  $totalMuacMeasured = 0;
  $totalWfaMeasured = 0;
  $totalHfaMeasured = 0;
  $totalWflMeasured = 0;

  $ipBoys = 0;
  $ipGirls = 0;
  $ipTotal = 0;

  $f1kTotal = 0;
  $children0to23 = 0;

  $wastedOrStunted0to59 = 0;
  $wastedOrStunted24to59 = 0;
  $overweightOrObese0to59 = 0;

  $mothersCaregivers0to59 = 0;
  $mothersCaregiversAffected = 0;
  $mothersCaregiversOwOb = 0;
  $mothersCaregivers0to23 = 0;

  $repeatedNameBirthdate = 0;
  $missingInformation = 0;
  $noParentOrAddress = 0;
  $noSexData = 0;

  $seenCaregivers0to59 = [];
  $seenCaregiversAffected = [];
  $seenCaregiversOwOb = [];
  $seenCaregivers0to23 = [];

  $duplicateKeyMap = [];

  foreach ($rows as $row) {
    $sexKey = sex_key($row['sex'] ?? '');
    $ageKey = age_bucket((int)($row['age_months'] ?? -1));
    $ageMonths = (int)($row['age_months'] ?? -1);

    if (!$sexKey || !$ageKey) {
      if (!$sexKey) {
        $noSexData++;
      }
      continue;
    }

    $totalChildren++;
    if ($sexKey === 'boys') $totalBoys++;
    if ($sexKey === 'girls') $totalGirls++;

    if (is_yes($row['ip_group'] ?? '')) {
      $ipTotal++;
      if ($sexKey === 'boys') $ipBoys++;
      if ($sexKey === 'girls') $ipGirls++;
    }

    if ($ageMonths >= 0 && $ageMonths <= 23) {
      $children0to23++;
      $f1kTotal++;
    }

    $caregiverKey = normalize_text(
      ($row['g_lastname'] ?? '') . '|' .
      ($row['g_firstname'] ?? '') . '|' .
      ($row['g_middlename'] ?? '')
    );

    if ($caregiverKey !== '||') {
      $seenCaregivers0to59[$caregiverKey] = true;
      if ($ageMonths <= 23) {
        $seenCaregivers0to23[$caregiverKey] = true;
      }
    }

    $dupKey = normalize_text(
      ($row['c_lastname'] ?? '') . '|' .
      ($row['c_firstname'] ?? '') . '|' .
      ($row['c_middlename'] ?? '') . '|' .
      ($row['date_birth'] ?? '')
    );
    if ($dupKey !== '|||') {
      if (!isset($duplicateKeyMap[$dupKey])) {
        $duplicateKeyMap[$dupKey] = 0;
      }
      $duplicateKeyMap[$dupKey]++;
    }

    $hasMissingInfo =
      trim((string)($row['c_lastname'] ?? '')) === '' ||
      trim((string)($row['c_firstname'] ?? '')) === '' ||
      trim((string)($row['date_birth'] ?? '')) === '';

    if ($hasMissingInfo) {
      $missingInformation++;
    }

    $hasNoParentOrAddress =
      (
        trim((string)($row['g_lastname'] ?? '')) === '' &&
        trim((string)($row['g_firstname'] ?? '')) === ''
      ) ||
      trim((string)($row['purok'] ?? '')) === '';

    if ($hasNoParentOrAddress) {
      $noParentOrAddress++;
    }

    $wfaKey = status_group_wfa($row['weight_status'] ?? '');
    if ($wfaKey !== null) {
      increment_matrix($wfa, $wfaKey, $ageKey, $sexKey);
      $totalWfaMeasured++;
    }

    $hfaKey = status_group_hfa($row['height_status'] ?? '');
    if ($hfaKey !== null) {
      increment_matrix($hfa, $hfaKey, $ageKey, $sexKey);
      $totalHfaMeasured++;
    }

    $wflKey = status_group_wfl($row['lt_status'] ?? '');
    if ($wflKey !== null) {
      increment_matrix($wfl, $wflKey, $ageKey, $sexKey);
      $totalWflMeasured++;
    }

    $muacKey = status_group_muac($row['muac_status'] ?? '');
    if ($muacKey !== null) {
      increment_matrix($muac, $muacKey, $ageKey, $sexKey);
      $totalMuacMeasured++;
    }

    $isWastedOrStunted =
      in_array($wflKey, ['mw_mam', 'sw_sam'], true) ||
      in_array($hfaKey, ['mst', 'sst'], true);

    $isOwOb = in_array($wflKey, ['ow', 'ob'], true);

    if ($isWastedOrStunted) {
      $wastedOrStunted0to59++;
      if ($ageMonths >= 24 && $ageMonths <= 59) {
        $wastedOrStunted24to59++;
      }
      if ($caregiverKey !== '||') {
        $seenCaregiversAffected[$caregiverKey] = true;
      }
    }

    if ($isOwOb) {
      $overweightOrObese0to59++;
      if ($caregiverKey !== '||') {
        $seenCaregiversOwOb[$caregiverKey] = true;
      }
    }
  }

  foreach ($duplicateKeyMap as $k => $count) {
    if ($count > 1) {
      $repeatedNameBirthdate += $count;
    }
  }

  $mothersCaregivers0to59 = count($seenCaregivers0to59);
  $mothersCaregiversAffected = count($seenCaregiversAffected);
  $mothersCaregiversOwOb = count($seenCaregiversOwOb);
  $mothersCaregivers0to23 = count($seenCaregivers0to23);

  $spreadsheet = IOFactory::load($templatePath);
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle('OPT Plus Form B');

  $cityName = $barangay['city_name'] ?? '';
  $provinceName = $barangay['province_name'] ?? '';

  $sheet->setCellValue('B10', $barangay['barangay_name'] ?? '');
  $sheet->setCellValue('B11', $cityName);
  $sheet->setCellValue('H9', $provinceName);
  $sheet->setCellValue('Q10', 'DOH - EB 2025');
  $sheet->setCellValue('Q11', month_name_from_number($month) . ' ' . $year);

  $sheet->setCellValue('H10', $totalChildren);
  $sheet->setCellValue('H11', $totalChildren);
  $sheet->setCellValue('N11', $totalChildren > 0 ? $totalChildren : 0);

  $sheet->setCellValue('Q9', prevalence(sum_status_total($wfa, 'muw') + sum_status_total($wfa, 'suw'), max($totalWfaMeasured, 1)));
  $sheet->setCellValue('Q12', prevalence(sum_status_total($hfa, 'mst') + sum_status_total($hfa, 'sst'), max($totalHfaMeasured, 1)));

  $sheet->setCellValue('V10', $totalChildren);
  $sheet->setCellValue('V9', $totalChildren);

  $sheet->setCellValue('D14', $totalBoys);
  $sheet->setCellValue('G14', $totalGirls);
  $sheet->setCellValue('J14', $totalMuacMeasured);
  $sheet->setCellValue('M14', $totalWfaMeasured);
  $sheet->setCellValue('P14', $totalHfaMeasured);
  $sheet->setCellValue('S14', $totalWflMeasured);
  $sheet->setCellValue('W14', $f1kTotal);
  $sheet->setCellValue('Y14', $ipBoys);
  $sheet->setCellValue('Z14', $ipGirls);
  $sheet->setCellValue('AA14', $ipTotal);

  $ageCols = [
    '0_5'   => ['B', 'C', 'D'],
    '6_11'  => ['E', 'F', 'G'],
    '12_23' => ['H', 'I', 'J'],
    '24_35' => ['K', 'L', 'M'],
    '36_47' => ['N', 'O', 'P'],
    '48_59' => ['Q', 'R', 'S']
  ];

  $rowMap = [
    'wfa.normal'   => 17,
    'wfa.muw'      => 20,
    'wfa.suw'      => 21,

    'hfa.normal'   => 22,
    'hfa.tall'     => 23,
    'hfa.mst'      => 24,
    'hfa.sst'      => 25,

    'wfl.normal'   => 26,
    'wfl.ow'       => 27,
    'wfl.ob'       => 28,
    'wfl.mw_mam'   => 29,
    'wfl.sw_sam'   => 30,

    'muac.normal'  => 31,
    'muac.mw_mam'  => 32,
    'muac.sw_sam'  => 33
  ];

  $matrices = [
    'wfa'  => $wfa,
    'hfa'  => $hfa,
    'wfl'  => $wfl,
    'muac' => $muac
  ];

  foreach ($rowMap as $key => $excelRow) {
    [$type, $status] = explode('.', $key, 2);
    $matrix = $matrices[$type];

    foreach ($ageCols as $ageKey => $cols) {
      [$boyCol, $girlCol, $totalCol] = $cols;
      $sheet->setCellValue($boyCol . $excelRow, (int)$matrix[$status][$ageKey]['boys']);
      $sheet->setCellValue($girlCol . $excelRow, (int)$matrix[$status][$ageKey]['girls']);
      $sheet->setCellValue($totalCol . $excelRow, (int)$matrix[$status][$ageKey]['total']);
    }

    $totalAll = sum_status_total($matrix, $status);
    $total0to23 = sum_status_0_23($matrix, $status);

    $sheet->setCellValue('T' . $excelRow, $totalAll);
    $sheet->setCellValue('U' . $excelRow, prevalence($totalAll, max($totalChildren, 1)));
    $sheet->setCellValue('V' . $excelRow, $total0to23);
    $sheet->setCellValue('W' . $excelRow, prevalence($total0to23, max($children0to23, 1)));
  }

  foreach ($ageCols as $ageKey => $cols) {
    [$boyCol, $girlCol, $totalCol] = $cols;

    $boys =
      (int)$wfa['normal'][$ageKey]['boys'] +
      (int)$wfa['muw'][$ageKey]['boys'] +
      (int)$wfa['suw'][$ageKey]['boys'];

    $girls =
      (int)$wfa['normal'][$ageKey]['girls'] +
      (int)$wfa['muw'][$ageKey]['girls'] +
      (int)$wfa['suw'][$ageKey]['girls'];

    $total =
      (int)$wfa['normal'][$ageKey]['total'] +
      (int)$wfa['muw'][$ageKey]['total'] +
      (int)$wfa['suw'][$ageKey]['total'];

    $sheet->setCellValue($boyCol . '34', $boys);
    $sheet->setCellValue($girlCol . '34', $girls);
    $sheet->setCellValue($totalCol . '34', $total);
  }

  $sheet->setCellValue('H36', $wastedOrStunted0to59);
  $sheet->setCellValue('H37', $wastedOrStunted24to59);
  $sheet->setCellValue('H38', $overweightOrObese0to59);
  $sheet->setCellValue('H39', $children0to23);

  $sheet->setCellValue('T36', $mothersCaregivers0to59);
  $sheet->setCellValue('T37', $mothersCaregiversAffected);
  $sheet->setCellValue('T38', $mothersCaregiversOwOb);
  $sheet->setCellValue('T39', $mothersCaregivers0to23);

  $sheet->setCellValue('Z36', $repeatedNameBirthdate);
  $sheet->setCellValue('Z37', $missingInformation);
  $sheet->setCellValue('Z38', $noParentOrAddress);
  $sheet->setCellValue('Z39', $noSexData);

  $filename = sprintf(
    'OPT_Plus_Form_B_%s_%04d_%02d.xlsx',
    preg_replace('/[^A-Za-z0-9_\-]/', '_', $barangay['barangay_name']),
    $year,
    $month
  );

  $exportedCount = mark_measurements_as_exported($pdo, $measureIds);

  audit_log(
    $pdo,
    $userId,
    'REPORT_EXPORTED',
    'opt_plus_form_b',
    (string)$barangayId,
    "Exported OPT Plus Form B for barangay={$barangay['barangay_name']} ({$barangayId}), period={$year}-" .
    str_pad((string)$month, 2, '0', STR_PAD_LEFT) .
    ", rows=" . count($rows) .
    ", measurements_marked={$exportedCount}"
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
      'opt_plus_form_b',
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