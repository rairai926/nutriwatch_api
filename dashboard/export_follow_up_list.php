<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");

// CORS
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
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$user = authenticate(['admin', 'user']);
$role = $user->role ?? 'user';
$userId = (int)($user->sub ?? 0);
$filter = trim((string)($_GET['filter'] ?? 'all'));

$allowedFilters = ['all', 'pending', 'visited', 'high-risk', 'overdue'];
if (!in_array($filter, $allowedFilters, true)) {
  $filter = 'all';
}

// Get BNS barangay_id
$barangayId = null;
if ($role !== 'admin') {
  $st = $pdo->prepare("SELECT barangay_id FROM tbl_users WHERE users_id = ? LIMIT 1");
  $st->execute([$userId]);
  $barangayId = (int)($st->fetchColumn() ?: 0);

  if ($barangayId <= 0) {
    http_response_code(403);
    echo json_encode(["message" => "No barangay assigned to this account"]);
    exit;
  }
}

$sql = "
  SELECT
    ci.child_seq,
    ci.c_firstname,
    ci.c_middlename,
    ci.c_lastname,
    ci.sex,
    b.barangay_name,
    lm.last_date AS date_measured,
    m.weight_status,
    m.height_status,
    m.lt_status,
    m.muac_status,
    fv.last_visited_at
  FROM tbl_child_info ci
  JOIN tbl_barangay b ON b.barangay_id = ci.barangay_id

  LEFT JOIN (
    SELECT child_seq, MAX(date_measured) AS last_date
    FROM tbl_measurement
    GROUP BY child_seq
  ) lm ON lm.child_seq = ci.child_seq

  LEFT JOIN tbl_measurement m
    ON m.child_seq = ci.child_seq
   AND m.date_measured = lm.last_date

  LEFT JOIN (
    SELECT child_seq, MAX(visited_at) AS last_visited_at
    FROM tbl_follow_up_visits
    GROUP BY child_seq
  ) fv ON fv.child_seq = ci.child_seq

  " . ($role !== 'admin' ? "WHERE ci.barangay_id = ?" : "") . "
";

$st = $pdo->prepare($sql);
$st->execute($role !== 'admin' ? [$barangayId] : []);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = [];

foreach ($rows as $r) {
  $lastDate = $r['date_measured'] ?? null;

  $weight = strtolower(trim((string)($r['weight_status'] ?? '')));
  $height = strtolower(trim((string)($r['height_status'] ?? '')));
  $lt = strtolower(trim((string)($r['lt_status'] ?? '')));
  $muac = strtolower(trim((string)($r['muac_status'] ?? '')));

  $isOverdue = !$lastDate || strtotime($lastDate) < strtotime('-90 days');

  $isHighRisk =
    in_array($weight, ['underweight', 'severely underweight'], true) ||
    in_array($height, ['stunted', 'severely stunted'], true) ||
    in_array($lt, ['wasted', 'severely wasted', 'overweight', 'obese'], true) ||
    str_contains($muac, 'yellow') ||
    str_contains($muac, 'red') ||
    str_contains($muac, 'mam') ||
    str_contains($muac, 'sam');

  $lastVisitedAt = $r['last_visited_at'] ?? null;
  $visitedRecently = $lastVisitedAt && strtotime($lastVisitedAt) >= strtotime('-30 days');

  $statusTag = $isHighRisk ? 'high-risk' : ($isOverdue ? 'overdue' : 'normal');
  $visitTag = $visitedRecently ? 'visited' : 'pending';

  if ($filter === 'high-risk' && !$isHighRisk) continue;
  if ($filter === 'overdue' && !$isOverdue) continue;
  if ($filter === 'pending' && $visitedRecently) continue;
  if ($filter === 'visited' && !$visitedRecently) continue;

  $data[] = [
    'child_name' => trim(implode(' ', array_filter([
      $r['c_firstname'] ?? '',
      $r['c_middlename'] ?? '',
      $r['c_lastname'] ?? ''
    ]))),
    'sex' => $r['sex'] ?? '',
    'barangay_name' => $r['barangay_name'] ?? '',
    'date_measured' => $lastDate ?: '',
    'status_tag' => $statusTag,
    'visit_tag' => $visitTag,
    'last_visited_at' => $lastVisitedAt ?: '',
    'weight_status' => $r['weight_status'] ?? '',
    'height_status' => $r['height_status'] ?? '',
    'lt_status' => $r['lt_status'] ?? '',
    'muac_status' => $r['muac_status'] ?? ''
  ];
}

usort($data, function ($a, $b) {
  $rank = ['high-risk' => 1, 'overdue' => 2, 'normal' => 3];
  $visit = ['pending' => 1, 'visited' => 2];

  if ($visit[$a['visit_tag']] !== $visit[$b['visit_tag']]) {
    return $visit[$a['visit_tag']] <=> $visit[$b['visit_tag']];
  }
  if ($rank[$a['status_tag']] !== $rank[$b['status_tag']]) {
    return $rank[$a['status_tag']] <=> $rank[$b['status_tag']];
  }
  return strcmp($a['date_measured'] ?: '1900-01-01', $b['date_measured'] ?: '1900-01-01');
});

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Follow-Up List');

$headers = [
  'Child Name',
  'Sex',
  'Barangay',
  'Last Measured',
  'Status',
  'Visit',
  'Last Visited',
  'Weight Status',
  'Height Status',
  'LT Status',
  'MUAC Status'
];

$col = 'A';
foreach ($headers as $header) {
  $sheet->setCellValue($col . '1', $header);
  $col++;
}

$rowNum = 2;
foreach ($data as $item) {
  $sheet->setCellValue("A{$rowNum}", $item['child_name']);
  $sheet->setCellValue("B{$rowNum}", $item['sex']);
  $sheet->setCellValue("C{$rowNum}", $item['barangay_name']);
  $sheet->setCellValue("D{$rowNum}", $item['date_measured']);
  $sheet->setCellValue("E{$rowNum}", $item['status_tag']);
  $sheet->setCellValue("F{$rowNum}", $item['visit_tag']);
  $sheet->setCellValue("G{$rowNum}", $item['last_visited_at']);
  $sheet->setCellValue("H{$rowNum}", $item['weight_status']);
  $sheet->setCellValue("I{$rowNum}", $item['height_status']);
  $sheet->setCellValue("J{$rowNum}", $item['lt_status']);
  $sheet->setCellValue("K{$rowNum}", $item['muac_status']);
  $rowNum++;
}

foreach (range('A', 'K') as $columnID) {
  $sheet->getColumnDimension($columnID)->setAutoSize(true);
}

$sheet->getStyle('A1:K1')->getFont()->setBold(true);

$filename = 'follow_up_list_' . $filter . '_' . date('Ymd_His') . '.xlsx';

ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;