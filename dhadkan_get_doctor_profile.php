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
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);


// Include database connection
if (!file_exists('dhadkan_db.php')) {
    error_log("dhadkan_db.php file not found");
    sendResponse(false, null, 'Database configuration file not found', 500);
}

require_once 'dhadkan_db.php';

// Response function
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

try {
    // Get doctor ID from query parameter
    $doctor_id = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
    
    // Validate doctor ID
    if ($doctor_id <= 0) {
        sendResponse(false, null, 'Invalid doctor ID provided', 400);
    }
    
    // Fetch doctor profile details
    $stmt = $conn->prepare("
        SELECT 
            id, 
            doctorName, 
            hospitalType, 
            hospitalname, 
            phoneNo, 
            experience, 
            email, 
            createdAt, 
            updatedAt 
        FROM doctors 
        WHERE id = ?
    ");
    
    if (!$stmt) {
        sendResponse(false, null, 'Database prepare failed: ' . $conn->error, 500);
    }
    
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, null, 'Doctor not found', 404);
    }
    
    $doctor = $result->fetch_assoc();
    
    // Fetch doctor statistics
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as totalChildrenScreened,
            SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as positiveCases,
            SUM(CASE WHEN DATE(createdat) = CURDATE() THEN 1 ELSE 0 END) as todayScreenings,
            SUM(CASE WHEN YEARWEEK(createdat, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) as reportsThisWeek
        FROM children 
        WHERE dr_id = ?
    ");
    
    if (!$stats_stmt) {
        sendResponse(false, null, 'Statistics query prepare failed: ' . $conn->error, 500);
    }
    
    $stats_stmt->bind_param("i", $doctor_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
    
    // Format response data to match DoctorProfile.tsx expectations
    $response_data = [
        'profile' => [
            'id' => intval($doctor['id']),
            'doctorName' => $doctor['doctorName'] ?? '',
            'hospitalType' => $doctor['hospitalType'] ?? '',
            'hospitalname' => $doctor['hospitalname'] ?? '',
            'phoneNo' => $doctor['phoneNo'] ?? '',
            'experience' => $doctor['experience'] ? intval($doctor['experience']) : null,
            'email' => $doctor['email'] ?? '',
            'createdAt' => $doctor['createdAt'],
            'updatedAt' => $doctor['updatedAt']
        ],
        'statistics' => [
            'totalChildrenScreened' => intval($stats['totalChildrenScreened'] ?? 0),
            'positiveCases' => intval($stats['positiveCases'] ?? 0),
            'todayScreenings' => intval($stats['todayScreenings'] ?? 0),
            'reportsThisWeek' => intval($stats['reportsThisWeek'] ?? 0),
            'pendingReports' => 0 // You can add logic for pending reports if needed
        ]
    ];
    
    // Close statements
    $stmt->close();
    $stats_stmt->close();
    
    sendResponse(true, $response_data, 'Doctor profile fetched successfully');
    
} catch (Exception $e) {
    error_log("Doctor Profile API Error: " . $e->getMessage());
    sendResponse(false, null, 'Internal server error: ' . $e->getMessage(), 500);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>
