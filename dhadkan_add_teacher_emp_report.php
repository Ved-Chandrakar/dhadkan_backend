
<?php
// Enhanced teacher/employee report API with proper CORS and database integration
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
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include database connection
if (!file_exists('dhadkan_db.php')) {
    error_log("dhadkan_db.php file not found in: " . __DIR__);
    sendResponse(false, null, 'Database configuration file not found', 500);
}

require_once 'dhadkan_db.php';

function sendResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Only POST method allowed', 405);
}

try {
    // Log raw input for debugging
    $json = file_get_contents('php://input');
    error_log("Teacher/Employee API - Raw JSON: " . $json);

    // Decode JSON
    $data = json_decode($json, true);
    if (!$data) {
        error_log("Teacher/Employee API - JSON decode failed");
        sendResponse(false, null, 'Invalid JSON payload', 400);
    }

    // Log parsed data for debugging
    error_log("Teacher/Employee API - Parsed Data: " . print_r($data, true));

    // Check database connection
    if (!isset($conn) || $conn->connect_errno) {
        error_log("Teacher/Employee API - Database connection failed: " . ($conn->connect_error ?? 'Connection object not found'));
        sendResponse(false, null, 'Database connection failed', 500);
    }

    // Determine the category (for both teacher and employee, no fatherName required)
    $category = isset($data['category']) ? $data['category'] : 'teacher'; // default to teacher if not specified
    error_log("Teacher/Employee API - Category: " . $category);
    
    // Required fields for both teacher and employee (note: no fatherName for either)
    $required = ['name', 'age', 'gender', 'mobileNo', 'schoolName', 'heartStatus', 'dr_id', 'haveAadhar', 'haveShramik'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            error_log("Teacher/Employee API - Missing field: $field");
            sendResponse(false, null, "Missing required field: $field", 422);
        }
    }

    error_log("Teacher/Employee API - All required fields present");

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

    // Determine upload directory based on category
    $uploadType = ($category === 'employee') ? 'employee' : 'teacher';

    if (!empty($data['aadharPhoto']) && is_array($data['aadharPhoto'])) {
        $aadharPath = saveBase64Image($data['aadharPhoto'], 'aadhar', $uploadType);
        if (!$aadharPath) {
            sendResponse(false, null, 'Failed to save Aadhar photo', 500);
        }
    }

    if (!empty($data['shramikPhoto']) && is_array($data['shramikPhoto'])) {
        $shramikPath = saveBase64Image($data['shramikPhoto'], 'shramik', $uploadType);
        if (!$shramikPath) {
            sendResponse(false, null, 'Failed to save Shramik photo', 500);
        }
    }

    // Insert into appropriate table based on category
    if ($category === 'employee') {
        // Insert into employee table
        $stmt = $conn->prepare("
            INSERT INTO employee (
                e_dr_id, e_name, e_age, e_gender, e_mobileNo, e_schoolName, 
                e_haveAadhar, e_haveShramik, e_aadharPhoto, e_shramikPhoto, 
                e_heartStatus, e_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Prepare failed for employee: " . $conn->error);
            sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
        }

        // Log the values being bound for debugging
        error_log("Teacher/Employee API - Binding values for EMPLOYEE: " . 
            "dr_id: " . $data['dr_id'] . ", " .
            "name: " . $data['name'] . ", " .
            "category: employee");

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
            $employeeId = $conn->insert_id;
            
            // Return success response
            sendResponse(true, [
                'employee' => [
                    'id' => $employeeId,
                    'name' => $data['name'],
                    'age' => $data['age'],
                    'gender' => $data['gender'],
                    'mobileNo' => $data['mobileNo'],
                    'schoolName' => $data['schoolName'],
                    'heartStatus' => $data['heartStatus'],
                    'category' => 'employee'
                ]
            ], 'Employee report added successfully');
        } else {
            $errorMessage = $stmt->error;
            error_log("Database insert failed for employee: " . $errorMessage);
            
            // Check for specific errors
            if (strpos($errorMessage, 'Duplicate entry') !== false && strpos($errorMessage, 'mobileNo') !== false) {
                sendResponse(false, null, 'इस मोबाइल नंबर से पहले से ही एक रिपोर्ट दर्ज है। कृपया अलग मोबाइल नंबर का उपयोग करें।', 422);
            } else {
                sendResponse(false, null, 'Failed to save employee report: ' . $errorMessage, 500);
            }
        }
        
    } else {
        // Insert into teacher table (default)
        $stmt = $conn->prepare("
            INSERT INTO teacher (
                t_dr_id, t_name, t_age, t_gender, t_mobileNo, t_schoolName, 
                t_haveAadhar, t_haveShramik, t_aadharPhoto, t_shramikPhoto, 
                t_heartStatus, t_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Prepare failed for teacher: " . $conn->error);
            sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
        }

        // Log the values being bound for debugging
        error_log("Teacher/Employee API - Binding values for TEACHER: " . 
            "dr_id: " . $data['dr_id'] . ", " .
            "name: " . $data['name'] . ", " .
            "category: teacher");

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
                    'heartStatus' => $data['heartStatus'],
                    'category' => 'teacher'
                ]
            ], 'Teacher report added successfully');
        } else {
            $errorMessage = $stmt->error;
            error_log("Database insert failed for teacher: " . $errorMessage);
            
            // Check for specific errors
            if (strpos($errorMessage, 'Duplicate entry') !== false && strpos($errorMessage, 'mobileNo') !== false) {
                sendResponse(false, null, 'इस मोबाइल नंबर से पहले से ही एक रिपोर्ट दर्ज है। कृपया अलग मोबाइल नंबर का उपयोग करें।', 422);
            } else {
                sendResponse(false, null, 'Failed to save teacher report: ' . $errorMessage, 500);
            }
        }
    }

} catch (Exception $e) {
    error_log("Teacher/Employee API - Exception: " . $e->getMessage());
    error_log("Teacher/Employee API - Stack trace: " . $e->getTraceAsString());
    sendResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log("Teacher/Employee API - Fatal Error: " . $e->getMessage());
    error_log("Teacher/Employee API - Stack trace: " . $e->getTraceAsString());
    sendResponse(false, null, 'Server error: ' . $e->getMessage(), 500);
}

function saveBase64Image($imageData, $type, $category = 'teacher') {
    if (!isset($imageData['data']) || !isset($imageData['name'])) {
        return null;
    }

    $base64Data = $imageData['data'];
    $fileName = $imageData['name'];
    
    // Create uploads directory based on category if it doesn't exist
    $uploadDir = 'uploads/' . $category . '_' . $type . '/';
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
