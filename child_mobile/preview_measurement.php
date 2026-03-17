<?php
ob_start();
session_start();

header("Content-Type: application/json; charset=utf-8");
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../middleware/auth.php";
require_once __DIR__ . "/../helpers/nutrition_status_helper.php";

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        throw new Exception("Method not allowed");
    }

    $user = authenticate($pdo);
    if (!$user || empty($user["user_id"])) {
        http_response_code(401);
        throw new Exception("Unauthorized");
    }

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        throw new Exception("Invalid JSON body");
    }

    $childSeq = isset($data["child_seq"]) ? (int)$data["child_seq"] : 0;
    $dateMeasured = trim((string)($data["date_measured"] ?? ""));
    $assessmentMethod = trim((string)($data["assessment_method"] ?? "Weight + Length/Height"));
    $weight = isset($data["weight"]) && $data["weight"] !== "" ? (float)$data["weight"] : null;
    $height = isset($data["height"]) && $data["height"] !== "" ? (float)$data["height"] : null;
    $muac = isset($data["muac"]) && $data["muac"] !== "" ? (float)$data["muac"] : null;
    $bilateralPitting = trim((string)($data["bilateral_pitting"] ?? "No"));

    if ($childSeq <= 0) {
        http_response_code(400);
        throw new Exception("child_seq is required");
    }

    if ($dateMeasured === "") {
        http_response_code(400);
        throw new Exception("date_measured is required");
    }

    $stmt = $pdo->prepare("
        SELECT
            child_seq,
            sex,
            date_birth,
            barangay_id
        FROM tbl_child_info
        WHERE child_seq = ?
        LIMIT 1
    ");
    $stmt->execute([$childSeq]);
    $child = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$child) {
        http_response_code(404);
        throw new Exception("Child not found");
    }

    if (!empty($user["barangay_id"]) && (int)$user["barangay_id"] !== (int)$child["barangay_id"]) {
        http_response_code(403);
        throw new Exception("You are not allowed to access this child");
    }

    $dateBirth = trim((string)($child["date_birth"] ?? ""));
    $sex = trim((string)($child["sex"] ?? ""));

    if ($dateBirth === "") {
        http_response_code(400);
        throw new Exception("Child date of birth is missing");
    }

    $birth = new DateTime($dateBirth);
    $measured = new DateTime($dateMeasured);

    if ($measured < $birth) {
        http_response_code(400);
        throw new Exception("Date measured cannot be earlier than date of birth");
    }

    $diff = $birth->diff($measured);
    $ageMonths = ($diff->y * 12) + $diff->m;
    $ageDays = (int)$diff->days;

    $weightStatus = null;
    $heightStatus = null;
    $ltStatus = null;
    $muacStatus = null;

    if ($assessmentMethod === "Weight + Length/Height") {
        if ($weight === null || $height === null) {
            http_response_code(400);
            throw new Exception("weight and height are required for this assessment method");
        }

        $calc = calculateNutritionStatus(
            $sex,
            $dateBirth,
            $dateMeasured,
            $weight,
            $height,
            null,
            $bilateralPitting
        );

        $weightStatus = $calc["weight_status"] ?? null;
        $heightStatus = $calc["height_status"] ?? null;
        $ltStatus = $calc["lt_status"] ?? null;
        $muacStatus = $calc["muac_status"] ?? null;
    } elseif ($assessmentMethod === "MUAC") {
        if ($muac === null) {
            http_response_code(400);
            throw new Exception("muac is required for this assessment method");
        }

        $calc = calculateNutritionStatus(
            $sex,
            $dateBirth,
            $dateMeasured,
            null,
            null,
            $muac,
            $bilateralPitting
        );

        $weightStatus = $calc["weight_status"] ?? null;
        $heightStatus = $calc["height_status"] ?? null;
        $ltStatus = $calc["lt_status"] ?? null;
        $muacStatus = $calc["muac_status"] ?? null;
    } else {
        http_response_code(400);
        throw new Exception("Invalid assessment_method");
    }

    ob_clean();
    echo json_encode([
        "ok" => true,
        "message" => "Preview computed successfully",
        "age_months" => $ageMonths,
        "age_days" => $ageDays,
        "weight_status" => $weightStatus,
        "height_status" => $heightStatus,
        "lt_status" => $ltStatus,
        "muac_status" => $muacStatus
    ]);
    exit;

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }

    ob_clean();
    echo json_encode([
        "ok" => false,
        "message" => "Preview failed",
        "error" => $e->getMessage()
    ]);
    exit;
}