<?php
// Enhanced login.php with proper CORS and database integration
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error logging for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
error_reporting(E_ALL);

// Include database connection
if (!file_exists('dhadkan_db.php')) {
    error_log("dhadkan_db.php file not found");
    sendResponse(false, null, 'Database configuration file not found', 500);
}

require_once 'dhadkan_db.php';
// Log raw input for debugging
$json = file_get_contents('php://input');
file_put_contents('form_debug.log', "Raw JSON:\n" . $json . "\n\n", FILE_APPEND);

// Decode JSON
$data = json_decode($json, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

// Log parsed data for debugging
file_put_contents('form_debug.log', "Parsed Data:\n" . print_r($data, true) . "\n\n", FILE_APPEND);

// Required fields
$required = ['name', 'age', 'gender', 'fatherName', 'mobileNo', 'schoolName', 'heartStatus', 'dr_id'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

// Extract values
$name = $data['name'];
$age = $data['age'] ?? null;
$gender = $data['gender'];
$fatherName = $data['fatherName'];
$mobileNo = $data['mobileNo'];
$schoolName = $data['schoolName'];
$heartStatus = $data['heartStatus'];
$notes = $data['notes'] ?? '';
$dr_id = $data['dr_id'];
$haveAadhar = $data['haveAadhar'] ?? '';
$haveShramik = $data['haveShramik'] ?? '';
$aadharPhoto = $data['aadharPhoto']['data'] ?? null;
$shramikPhoto = $data['shramikPhoto']['data'] ?? null;

// Optional: Save image files if provided
$aadharFileName = '';
$shramikFileName = '';
$uploadDir = "uploads/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($aadharPhoto) {
    $aadharFileName = $uploadDir . 'aadhar_' . time() . '.jpg';
    file_put_contents($aadharFileName, base64_decode($aadharPhoto));
}
if ($shramikPhoto) {
    $shramikFileName = $uploadDir . 'shramik_' . time() . '.jpg';
    file_put_contents($shramikFileName, base64_decode($shramikPhoto));
}

// DB insert
try {
    $pdo = new PDO("mysql:host=localhost;dbname=dhadkan;charset=utf8mb4", "root", "Ssipmt@2025DODB");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("INSERT INTO children 
        (dr_id, name, age, gender, fatherName, mobileNo, schoolName, heartStatus, notes, haveAadhar, haveShramik, aadharPhoto, shramikPhoto, createdAt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $stmt->execute([
        $dr_id,
        $name,
        $age,
        $gender,
        $fatherName,
        $mobileNo,
        $schoolName,
        $heartStatus,
        $notes,
        $haveAadhar,
        $haveShramik,
        $aadharFileName,
        $shramikFileName
    ]);

    $lastId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Form submitted and saved to DB successfully',
        'data' => [
            'child' => [
                'id' => $lastId,
                'name' => $name
            ]
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>
