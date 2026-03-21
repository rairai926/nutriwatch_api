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
    $userBarangayStmt = $pdo->prepare("
      SELECT barangay_id
      FROM tbl_users
      WHERE users_id = ?
      LIMIT 1
    ");
    $userBarangayStmt->execute([$userId]);
    $userBarangayId = (int)($userBarangayStmt->fetchColumn() ?: 0);

    if ($userBarangayId <= 0) {
      json_out(403, ["ok" => false, "message" => "No barangay assigned"]);
    }

    if ($userBarangayId !== $barangayId) {
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
    json_out(404, ["ok" => false, "message" => "Barangay not found"]);
  }

  $templatePath = __DIR__ . "/templates/opt_1b.xlsx";
  if (!file_exists($templatePath)) {
    json_out(500, ["ok" => false, "message" => "Excel template not found"]);
  }

  $spreadsheet = IOFactory::load($templatePath);
  $sheet = $spreadsheet->getActiveSheet();

  /*
    Adjust these cell mappings after checking your actual OPT 1B template.
    These are safe starter mappings.
  */
  $sheet->setCellValue('C1', $barangay['barangay_name']);
  $sheet->setCellValue('H1', 'Tangub City');
  $sheet->setCellValue('H2', 'Misamis Occidental');
  $sheet->setCellValue('K1', $year);
  $sheet->setCellValue('K2', month_name_from_number($month));

  /*
    Starter summary query.
    Adjust the fields once you confirm the exact structure of OPT 1B.
  */
  $stmt = $pdo->prepare("
    SELECT
      COUNT(*) AS total_children,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%normal%' THEN 1 ELSE 0 END) AS total_normal,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%underweight%' THEN 1 ELSE 0 END) AS total_underweight,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%severely underweight%' THEN 1 ELSE 0 END) AS total_severely_underweight,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%stunted%' THEN 1 ELSE 0 END) AS total_stunted,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%severely stunted%' THEN 1 ELSE 0 END) AS total_severely_stunted,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%wasted%' THEN 1 ELSE 0 END) AS total_wasted,
      SUM(CASE WHEN LOWER(COALESCE(m.lt_status, '')) LIKE '%severely wasted%' THEN 1 ELSE 0 END) AS total_severely_wasted
    FROM tbl_child_info ci
    INNER JOIN (
      SELECT t.child_seq, MAX(t.date_measured) AS latest_date_measured
      FROM tbl_measurement t
      WHERE YEAR(t.date_measured) = ?
        AND MONTH(t.date_measured) = ?
      GROUP BY t.child_seq
    ) latest
      ON latest.child_seq = ci.child_seq
    INNER JOIN tbl_measurement m
      ON m.child_seq = latest.child_seq
     AND m.date_measured = latest.latest_date_measured
    WHERE ci.barangay_id = ?
      AND TIMESTAMPDIFF(MONTH, ci.date_birth, m.date_measured) BETWEEN 0 AND 59
  ");
  $stmt->execute([$year, $month, $barangayId]);
  $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

  /*
    Replace these cells to match your actual template positions.
  */
  $sheet->setCellValue('B8', (int)($summary['total_children'] ?? 0));
  $sheet->setCellValue('B9', (int)($summary['total_normal'] ?? 0));
  $sheet->setCellValue('B10', (int)($summary['total_underweight'] ?? 0));
  $sheet->setCellValue('B11', (int)($summary['total_severely_underweight'] ?? 0));
  $sheet->setCellValue('B12', (int)($summary['total_stunted'] ?? 0));
  $sheet->setCellValue('B13', (int)($summary['total_severely_stunted'] ?? 0));
  $sheet->setCellValue('B14', (int)($summary['total_wasted'] ?? 0));
  $sheet->setCellValue('B15', (int)($summary['total_severely_wasted'] ?? 0));

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