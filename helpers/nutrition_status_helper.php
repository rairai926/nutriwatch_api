<?php

function nh_safe_float($v) {
  if ($v === null || $v === '') return null;
  return (float)$v;
}

function nh_safe_int($v) {
  if ($v === null || $v === '') return null;
  return (int)$v;
}

function nh_compute_age_months($dateBirth, $dateMeasured) {
  $birth = new DateTime($dateBirth);
  $measured = new DateTime($dateMeasured);

  if ($measured < $birth) return 0;

  $months =
    ((int)$measured->format('Y') - (int)$birth->format('Y')) * 12 +
    ((int)$measured->format('n') - (int)$birth->format('n'));

  if ((int)$measured->format('j') < (int)$birth->format('j')) {
    $months--;
  }

  return max(0, $months);
}

function nh_compute_age_days($dateBirth, $dateMeasured) {
  $birth = new DateTime($dateBirth);
  $measured = new DateTime($dateMeasured);

  if ($measured < $birth) return 0;
  return (int)$birth->diff($measured)->days;
}

function nh_round_to_half_cm($value) {
  if ($value === null || $value === '') return null;
  return round(((float)$value) * 2) / 2;
}

function nh_load_json_table($filePath) {
  if (!file_exists($filePath)) {
    throw new Exception("Reference file not found: " . basename($filePath));
  }

  $json = file_get_contents($filePath);
  $data = json_decode($json, true);

  if (!is_array($data)) {
    throw new Exception("Invalid JSON reference file: " . basename($filePath));
  }

  return $data;
}

function nh_find_row_by_key($table, $keyField, $keyValue) {
  foreach ($table as $row) {
    if ((string)$row[$keyField] === (string)$keyValue) {
      return $row;
    }
  }
  return null;
}

function nh_muac_status($muac, $bilateralPitting = 'No') {
  $muac = nh_safe_float($muac);

  if (strtolower((string)$bilateralPitting) === 'yes') {
    return 'SAM';
  }

  if ($muac === null || $muac <= 0) return null;
  if ($muac < 11.5) return 'SAM';
  if ($muac < 12.5) return 'MAM';
  return 'Normal';
}

/**
 * Weight-for-age JSON expected fields:
 * age_months,
 * severely_underweight_max,
 * underweight_max,
 * normal_max,
 * overweight_min
 */
function nh_weight_for_age_status($sex, $ageMonths, $weight) {
  $weight = nh_safe_float($weight);
  $ageMonths = nh_safe_int($ageMonths);
  $sex = strtolower(trim((string)$sex));

  if ($weight === null || $ageMonths === null) return null;

  $file = $sex === 'female'
    ? __DIR__ . '/../references/weight_for_age_girls.json'
    : __DIR__ . '/../references/weight_for_age_boys.json';

  $table = nh_load_json_table($file);
  $row = nh_find_row_by_key($table, 'age_months', $ageMonths);

  if (!$row) return null;

  if ($weight < (float)$row['severely_underweight_max']) return 'Severely Underweight';
  if ($weight < (float)$row['underweight_max']) return 'Underweight';
  if ($weight <= (float)$row['normal_max']) return 'Normal';
  return 'Overweight';
}

/**
 * Height-for-age JSON expected fields:
 * age_months,
 * severely_stunted_max,
 * stunted_max,
 * normal_max,
 * tall_min
 */
function nh_height_for_age_status($sex, $ageMonths, $height) {
  $height = nh_safe_float($height);
  $ageMonths = nh_safe_int($ageMonths);
  $sex = strtolower(trim((string)$sex));

  if ($height === null || $ageMonths === null) return null;

  $file = $sex === 'female'
    ? __DIR__ . '/../references/height_for_age_girls.json'
    : __DIR__ . '/../references/height_for_age_boys.json';

  $table = nh_load_json_table($file);
  $row = nh_find_row_by_key($table, 'age_months', $ageMonths);

  if (!$row) return null;

  if ($height < (float)$row['severely_stunted_max']) return 'Severely Stunted';
  if ($height < (float)$row['stunted_max']) return 'Stunted';
  if ($height <= (float)$row['normal_max']) return 'Normal';
  return 'Tall';
}

/**
 * Weight-for-length / weight-for-height JSON expected fields:
 * cm,
 * severely_wasted_max,
 * wasted_max,
 * normal_max,
 * overweight_max
 */
function nh_weight_for_length_height_status($sex, $ageMonths, $height, $weight, $bilateralPitting = 'No') {
  $sex = strtolower(trim((string)$sex));
  $ageMonths = nh_safe_int($ageMonths);
  $height = nh_safe_float($height);
  $weight = nh_safe_float($weight);

  if ($height === null || $weight === null || $ageMonths === null) return null;

  // edema override
  if (strtolower((string)$bilateralPitting) === 'yes') {
    return 'Severely Wasted';
  }

  $heightRounded = nh_round_to_half_cm($height);

  if ($ageMonths <= 23) {
    $file = $sex === 'female'
      ? __DIR__ . '/../references/weight_for_length_girls_0_23.json'
      : __DIR__ . '/../references/weight_for_length_boys_0_23.json';
  } else {
    $file = $sex === 'female'
      ? __DIR__ . '/../references/weight_for_height_girls_24_60.json'
      : __DIR__ . '/../references/weight_for_height_boys_24_60.json';
  }

  $table = nh_load_json_table($file);
  $row = nh_find_row_by_key($table, 'cm', $heightRounded);

  if (!$row) return null;

  if ($weight < (float)$row['severely_wasted_max']) return 'Severely Wasted';
  if ($weight < (float)$row['wasted_max']) return 'Wasted';
  if ($weight <= (float)$row['normal_max']) return 'Normal';
  if ($weight <= (float)$row['overweight_max']) return 'Overweight';
  return 'Obese';
}

function nh_compute_all_statuses($sex, $dateBirth, $dateMeasured, $weight, $height, $muac, $bilateralPitting = 'No') {
  $ageMonths = nh_compute_age_months($dateBirth, $dateMeasured);
  $ageDays = nh_compute_age_days($dateBirth, $dateMeasured);

  return [
    'age_months' => $ageMonths,
    'age_days' => $ageDays,
    'weight_status' => nh_weight_for_age_status($sex, $ageMonths, $weight),
    'height_status' => nh_height_for_age_status($sex, $ageMonths, $height),
    'lt_status' => nh_weight_for_length_height_status($sex, $ageMonths, $height, $weight, $bilateralPitting),
    'muac_status' => nh_muac_status($muac, $bilateralPitting)
  ];
}