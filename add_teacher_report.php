
<?php
// Enhanced teacher report API with proper CORS and database integration
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
ini_set('display_errors', 1);
file_put_contents("debug_log.txt", file_get_contents("php://input")); // optional debug

// Include database connection
if (!file_exists('db.php')) {
    error_log("db.php file not found");
    sendResponse(false, null, 'Database configuration file not found', 500);
}

require_once 'db.php';

function sendResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

try {
    // Log raw input for debugging
    $json = file_get_contents('php://input');
    file_put_contents('teacher_form_debug.log', "Raw JSON:\n" . $json . "\n\n", FILE_APPEND);

    // Decode JSON
    $data = json_decode($json, true);
    if (!$data) {
        sendResponse(false, null, 'Invalid JSON payload', 400);
    }

    // Log parsed data for debugging
    file_put_contents('teacher_form_debug.log', "Parsed Data:\n" . print_r($data, true) . "\n\n", FILE_APPEND);

    // Required fields for teacher (note: no fatherName for teachers)
    $required = ['name', 'age', 'gender', 'mobileNo', 'schoolName', 'heartStatus', 'dr_id', 'haveAadhar', 'haveShramik'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            file_put_contents('teacher_form_debug.log', "Missing field: $field\n", FILE_APPEND);
            sendResponse(false, null, "Missing required field: $field", 422);
        }
    }

    // Validate specific fields
    if (!in_array($data['gender'], ['पुरुष', 'महिला'])) {
        sendResponse(false, null, 'Invalid gender value', 422);
    }

    if (!in_array($data['heartStatus'], ['संदिग्ध', 'संदेह नहीं'])) {
        sendResponse(false, null, 'Invalid heart status value', 422);
    }

    if (!in_array($data['haveAadhar'], ['yes', 'no'])) {
        sendResponse(false, null, 'Invalid aadhar availability value', 422);
    }

    if (!in_array($data['haveShramik'], ['yes', 'no'])) {
        sendResponse(false, null, 'Invalid shramik availability value', 422);
    }

    // Validate age
    if (!is_numeric($data['age']) || $data['age'] < 1 || $data['age'] > 100) {
        sendResponse(false, null, 'Invalid age value', 422);
    }

    // Validate mobile number
    if (!preg_match('/^[0-9]{10}$/', $data['mobileNo'])) {
        sendResponse(false, null, 'Invalid mobile number format', 422);
    }

    // Handle file uploads (base64 images)
    $aadharPath = null;
    $shramikPath = null;

    if (!empty($data['aadharPhoto']) && is_array($data['aadharPhoto'])) {
        $aadharPath = saveBase64Image($data['aadharPhoto'], 'aadhar');
        if (!$aadharPath) {
            sendResponse(false, null, 'Failed to save Aadhar photo', 500);
        }
    }

    if (!empty($data['shramikPhoto']) && is_array($data['shramikPhoto'])) {
        $shramikPath = saveBase64Image($data['shramikPhoto'], 'shramik');
        if (!$shramikPath) {
            sendResponse(false, null, 'Failed to save Shramik photo', 500);
        }
    }

    // Insert into teacher table
    $stmt = $conn->prepare("
        INSERT INTO teacher (
            t_dr_id, t_name, t_age, t_gender, t_mobileNo, t_schoolName, 
            t_haveAadhar, t_haveShramik, t_aadharPhoto, t_shramikPhoto, 
            t_heartStatus, t_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
    }

    // Log the values being bound for debugging
    file_put_contents('teacher_form_debug.log', "Binding values:\n" . 
        "dr_id: " . $data['dr_id'] . "\n" .
        "name: " . $data['name'] . "\n" .
        "age: " . $data['age'] . "\n" .
        "gender: " . $data['gender'] . "\n" .
        "mobileNo: " . $data['mobileNo'] . "\n" .
        "schoolName: " . $data['schoolName'] . "\n" .
        "haveAadhar: " . $data['haveAadhar'] . "\n" .
        "haveShramik: " . $data['haveShramik'] . "\n" .
        "aadharPath: " . ($aadharPath ?? 'NULL') . "\n" .
        "shramikPath: " . ($shramikPath ?? 'NULL') . "\n" .
        "heartStatus: " . $data['heartStatus'] . "\n" .
        "notes: " . ($data['notes'] ?? '') . "\n\n", FILE_APPEND);

    // Prepare variables for binding (required for passing by reference)
    $dr_id = $data['dr_id'];
    $name = $data['name'];
    $age = $data['age'];
    $gender = $data['gender'];
    $mobileNo = $data['mobileNo'];
    $schoolName = $data['schoolName'];
    $haveAadhar = $data['haveAadhar'];
    $haveShramik = $data['haveShramik'];
    $heartStatus = $data['heartStatus'];
    $notes = $data['notes'] ?? '';

    $stmt->bind_param(
        "isisssssssss",
        $dr_id,
        $name,
        $age,
        $gender,
        $mobileNo,
        $schoolName,
        $haveAadhar,
        $haveShramik,
        $aadharPath,
        $shramikPath,
        $heartStatus,
        $notes
    );

    if ($stmt->execute()) {
        $teacherId = $conn->insert_id;
        
        // Return success response
        sendResponse(true, [
            'teacher' => [
                'id' => $teacherId,
                'name' => $data['name'],
                'age' => $data['age'],
                'gender' => $data['gender'],
                'mobileNo' => $data['mobileNo'],
                'schoolName' => $data['schoolName'],
                'heartStatus' => $data['heartStatus']
            ]
        ], 'Teacher report added successfully');
    } else {
        $errorMessage = $stmt->error;
        error_log("Database insert failed: " . $errorMessage);
        
        // Check for specific errors
        if (strpos($errorMessage, 'Duplicate entry') !== false && strpos($errorMessage, 'mobileNo') !== false) {
            sendResponse(false, null, 'इस मोबाइल नंबर से पहले से ही एक रिपोर्ट दर्ज है। कृपया अलग मोबाइल नंबर का उपयोग करें।', 422);
        } else {
            sendResponse(false, null, 'Failed to save teacher report: ' . $errorMessage, 500);
        }
    }

} catch (Exception $e) {
    error_log("Exception in add_teacher_report.php: " . $e->getMessage());
    sendResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}

function saveBase64Image($imageData, $type) {
    if (!isset($imageData['data']) || !isset($imageData['name'])) {
        return null;
    }

    $base64Data = $imageData['data'];
    $fileName = $imageData['name'];
    
    // Create uploads directory if it doesn't exist
    $uploadDir = 'uploads/teacher_' . $type . '/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $uniqueName;
    
    // Decode and save base64 image
    $imageContent = base64_decode($base64Data);
    if ($imageContent && file_put_contents($filePath, $imageContent)) {
        return $filePath;
    }
    
    return null;
}
?>
