<?php
// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to JSON
header('Content-Type: application/json');

// Enable CORS for frontend requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'केवल POST अनुरोध स्वीकार्य हैं'
    ]);
    exit();
}

// Include database connection
require_once 'db.php';

// Function to validate email format
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to validate phone number (10 digits)
function validatePhone($phone) {
    return preg_match('/^[0-9]{10}$/', $phone);
}

// Function to sanitize input
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Check if JSON is valid
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('अवैध JSON डेटा प्राप्त हुआ');
    }

    // Validate required fields
    $requiredFields = ['doctorName', 'email', 'phoneNo', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            throw new Exception("आवश्यक फ़ील्ड गुम है: {$field}");
        }
    }

    // Sanitize and validate input data
    $doctorName = sanitizeInput($data['doctorName']);
    $email = sanitizeInput($data['email']);
    $phoneNo = sanitizeInput($data['phoneNo']);
    $password = $data['password']; // Don't sanitize password as it might affect special characters
    $hospitalType = isset($data['hospitalType']) ? sanitizeInput($data['hospitalType']) : null;
    $hospitalname = isset($data['hospitalname']) ? sanitizeInput($data['hospitalname']) : null;
    $experience = isset($data['experience']) ? (int)$data['experience'] : 0;

    // Validate email format
    if (!validateEmail($email)) {
        throw new Exception('अवैध ईमेल पता');
    }

    // Validate phone number
    if (!validatePhone($phoneNo)) {
        throw new Exception('फ़ोन नंबर 10 अंकों का होना चाहिए');
    }

    // Validate doctor name length
    if (strlen($doctorName) < 2 || strlen($doctorName) > 100) {
        throw new Exception('चिकित्सक का नाम 2-100 वर्णों के बीच होना चाहिए');
    }

    // Validate password strength
    if (strlen($password) < 6) {
        throw new Exception('पासवर्ड कम से कम 6 वर्णों का होना चाहिए');
    }

    // Validate experience
    if ($experience < 0 || $experience > 50) {
        throw new Exception('अनुभव 0 से 50 वर्षों के बीच होना चाहिए');
    }

    // Check if email already exists
    $checkEmailQuery = "SELECT id FROM doctors WHERE email = ?";
    $checkStmt = $conn->prepare($checkEmailQuery);
    $checkStmt->bind_param('s', $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        throw new Exception('यह ईमेल पता पहले से पंजीकृत है');
    }
    $checkStmt->close();

    // Check if phone number already exists
    $checkPhoneQuery = "SELECT id FROM doctors WHERE phoneNo = ?";
    $checkPhoneStmt = $conn->prepare($checkPhoneQuery);
    $checkPhoneStmt->bind_param('s', $phoneNo);
    $checkPhoneStmt->execute();
    $phoneResult = $checkPhoneStmt->get_result();
    
    if ($phoneResult->num_rows > 0) {
        throw new Exception('यह फ़ोन नंबर पहले से पंजीकृत है');
    }
    $checkPhoneStmt->close();

    // Hash the password securely
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Get current timestamp
    $currentTimestamp = date('Y-m-d H:i:s');

    // Prepare insert query
    $insertQuery = "INSERT INTO doctors (doctorName, hospitalType, hospitalname, phoneNo, experience, email, password, createdAt, updatedAt) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($insertQuery);
    
    if (!$stmt) {
        throw new Exception('डेटाबेस तैयारी त्रुटि: ' . $conn->error);
    }

    // Bind parameters
    $stmt->bind_param('ssssissss', 
        $doctorName, 
        $hospitalType, 
        $hospitalname, 
        $phoneNo, 
        $experience, 
        $email, 
        $hashedPassword, 
        $currentTimestamp, 
        $currentTimestamp
    );

    // Execute the query
    if ($stmt->execute()) {
        $doctorId = $conn->insert_id;
        
        // Success response
        $response = [
            'success' => true,
            'message' => 'चिकित्सक सफलतापूर्वक जोड़ा गया',
            'data' => [
                'id' => $doctorId,
                'doctorName' => $doctorName,
                'email' => $email,
                'phoneNo' => $phoneNo,
                'hospitalType' => $hospitalType,
                'hospitalname' => $hospitalname,
                'experience' => $experience,
                'createdAt' => $currentTimestamp
            ]
        ];
        
        http_response_code(201); // Created
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        
        // Log successful addition
        error_log("New doctor added successfully: ID {$doctorId}, Email: {$email}");
        
    } else {
        throw new Exception('डेटाबेस में डेटा सहेजने में त्रुटि: ' . $stmt->error);
    }

    $stmt->close();

} catch (Exception $e) {
    // Error response
    $response = [
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'ADD_DOCTOR_ERROR'
    ];
    
    http_response_code(400); // Bad Request
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
    // Log the error
    error_log("Add doctor error: " . $e->getMessage());

} catch (mysqli_sql_exception $e) {
    // Database specific error
    $response = [
        'success' => false,
        'message' => 'डेटाबेस त्रुटि हुई',
        'error_code' => 'DATABASE_ERROR'
    ];
    
    http_response_code(500); // Internal Server Error
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
    // Log the database error
    error_log("Database error in add_doctor.php: " . $e->getMessage());

} finally {
    // Close database connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>
