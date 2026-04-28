<?php

require_once __DIR__."/../vendor/autoload.php";
require_once __DIR__."/../config/db.php";
require_once __DIR__."/../middleware/auth.php";

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function json_out($c,$p){
if(ob_get_length()) ob_clean();
http_response_code($c);
header("Content-Type: application/json");
echo json_encode($p);
exit;
}

function normalize_text($v){
$v=strtoupper(trim((string)$v));
return preg_replace('/\s+/',' ',$v);
}

function sex_value($sex){
$s=normalize_text($sex);
if(in_array($s,['M','MALE'])) return 'M';
if(in_array($s,['F','FEMALE'])) return 'F';
return $sex;
}

function clean_person_name($last,$first,$middle=''){
$n=trim($last);
if(trim($first)!=='') $n.=($n?', ':'').trim($first);
if(trim($middle)!=='') $n.=' '.trim($middle);
return trim($n);
}

function normalize_hfa($status){
$s=normalize_text($status);

if(in_array($s,['MODERATELY STUNTED','MST','STUNTED'])) return 'MSt';
if(in_array($s,['SEVERELY STUNTED','SST'])) return 'SSt';

return null;
}

function normalize_wfl($status){
$s=normalize_text($status);

if(in_array($s,['SEVERELY WASTED','SAM','SW','SW/SAM'])) return 'SW/SAM';

return null;
}

function mark_measurements_as_exported(PDO $pdo,array $ids){
$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
if(!$ids) return 0;

$p=implode(',',array_fill(0,count($ids),'?'));

$st=$pdo->prepare("
UPDATE tbl_measurement
SET is_exported_excel=1,
excel_exported_at=COALESCE(excel_exported_at,NOW())
WHERE measure_id IN($p)
");
$st->execute($ids);
return $st->rowCount();
}

function apply_data_row_style($sheet,$r){
$sheet->getRowDimension($r)->setRowHeight(24);

$sheet->getStyle("A{$r}:N{$r}")->applyFromArray([
'borders'=>[
'allBorders'=>[
'borderStyle'=>Border::BORDER_THIN
]
]
]);

$sheet->getStyle("A{$r}:N{$r}")
->getAlignment()
->setVertical(Alignment::VERTICAL_CENTER);
}

try{

$authUser=authenticate(['admin','user','bns']);
$userId=(int)($authUser->sub??0);

$barangayId=(int)($_GET['barangay_id']??0);
$year=(int)($_GET['year']??0);
$month=(int)($_GET['month']??0);

$startDate=sprintf('%04d-%02d-01',$year,$month);
$nextMonth=date('Y-m-d',strtotime($startDate.' +1 month'));

$b=$pdo->prepare("
SELECT 
b.barangay_name,
MAX(c.city_name) city_name
FROM tbl_barangay b
LEFT JOIN tbl_child_info ci ON ci.barangay_id=b.barangay_id
LEFT JOIN tbl_city c ON c.city_id=ci.city_id
WHERE b.barangay_id=?
GROUP BY b.barangay_name
");
$b->execute([$barangayId]);
$barangay=$b->fetch(PDO::FETCH_ASSOC);

$stmt=$pdo->prepare("
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
m.weight,
m.height,
m.height_status,
m.lt_status

FROM tbl_child_info ci

INNER JOIN(
SELECT child_seq,
MAX(measure_id) latest_measure_id
FROM tbl_measurement
WHERE date_measured>=?
AND date_measured<?
GROUP BY child_seq
)latest
ON latest.child_seq=ci.child_seq

INNER JOIN tbl_measurement m
ON m.measure_id=latest.latest_measure_id

WHERE ci.barangay_id=?
AND TIMESTAMPDIFF(
MONTH,
ci.date_birth,
CURDATE()
) BETWEEN 0 AND 59

ORDER BY
IFNULL(ci.purok,''),
ci.c_lastname,
ci.c_firstname
");

$stmt->execute([
$startDate,
$nextMonth,
$barangayId
]);

$data=$stmt->fetchAll(PDO::FETCH_ASSOC);

$rows=[];

foreach($data as $r){

$hfa=normalize_hfa($r['height_status']);
$wfl=normalize_wfl($r['lt_status']);

if(
$hfa!=='MSt'
&&
$wfl!=='SW/SAM'
){
continue;
}

$rows[]=[
'measure_id'=>(int)$r['measure_id'],
'child_seq'=>$r['child_seq'],
'address'=>$r['purok'],
'caregiver'=>clean_person_name(
$r['g_lastname'],
$r['g_firstname'],
$r['g_middlename']
),
'child_name'=>clean_person_name(
$r['c_lastname'],
$r['c_firstname'],
$r['c_middlename']
),
'sex'=>sex_value($r['sex']),
'birthdate'=>$r['date_birth'],
'weight'=>(float)$r['weight'],
'hfa'=>$hfa,
'wfl'=>$wfl
];
}

$total=count($rows);

$template=__DIR__."/templates/list_mstsw.xlsx";

if(!file_exists($template)){
json_out(500,[
'ok'=>false,
'message'=>'Excel template missing'
]);
}

$spreadsheet=IOFactory::load($template);
$sheet=$spreadsheet->getActiveSheet();

$sheet->setTitle("MSt-SAM");

$baseRows=14;
$startRow=11;

if($total>$baseRows){
$sheet->insertNewRowBefore(
25,
$total-$baseRows
);
}

$sheet->setCellValue('F6',$barangay['barangay_name']);
$sheet->setCellValue('J6',$barangay['city_name']);
$sheet->setCellValue('C7',$year);
$sheet->setCellValue('H8',$total);

if(!$total){

$sheet->mergeCells("A11:N11");
$sheet->setCellValue(
"A11",
'No matching children found.'
);

}else{

$rowNo=$startRow;

foreach($rows as $r){

$sheet->setCellValue("A$rowNo",$r['child_seq']);
$sheet->setCellValue("B$rowNo",$r['address']);
$sheet->setCellValue("C$rowNo",$r['caregiver']);
$sheet->setCellValue("D$rowNo",$r['child_name']);
$sheet->setCellValue("E$rowNo",$r['sex']);

if($r['birthdate']){
$sheet->setCellValue(
"F$rowNo",
ExcelDate::PHPToExcel(
strtotime($r['birthdate'])
)
);
}

$sheet->setCellValue("G$rowNo",$r['weight']);
$sheet->setCellValue("H$rowNo",$r['hfa']);
$sheet->setCellValue("I$rowNo",$r['wfl']);

$sheet->setCellValue("J$rowNo",'');
$sheet->setCellValue("K$rowNo",'');
$sheet->setCellValue("L$rowNo",'');
$sheet->setCellValue("M$rowNo",'');
$sheet->setCellValue("N$rowNo",'');

apply_data_row_style(
$sheet,
$rowNo
);

$rowNo++;
}

}

mark_measurements_as_exported(
$pdo,
array_column($rows,'measure_id')
);

$filename=sprintf(
'MSt_SAM_%s_%04d_%02d.xlsx',
preg_replace(
'/[^A-Za-z0-9_-]/',
'_',
$barangay['barangay_name']
),
$year,
$month
);

if(ob_get_length()) ob_end_clean();

header(
'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);

header(
'Content-Disposition: attachment; filename="'.$filename.'"'
);

$writer=new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

}catch(Throwable $e){

json_out(500,[
'ok'=>false,
'message'=>'Server error',
'error'=>$e->getMessage()
]);

}