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
require_once 'db.php';

// Log the request
error_log("Login request received: " . $_SERVER['REQUEST_METHOD']);

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input_raw = file_get_contents('php://input');
error_log("Raw input received: " . $input_raw);

$input = json_decode($input_raw, true);

// Validate JSON input
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Validate required fields
if (!isset($input['email']) || !isset($input['password']) || !isset($input['userType'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email, password और user type required हैं']);
    exit();
}

$email = trim($input['email']);
$password = trim($input['password']);
$userType = trim($input['userType']);

error_log("Login attempt - Email: $email, UserType: $userType");

// Validate user type
if (!in_array($userType, ['doctor', 'admin'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user type']);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit();
}

try {
    // Check database connection
    if (!isset($conn) || $conn->connect_errno) {
        throw new Exception("Database connection not available");
    }

    $user = null;
    $userInfo = null;

    if ($userType === 'doctor') {
        // Query for doctor based on your schema
        $stmt = $conn->prepare("SELECT id, doctorName, email, password, hospitalType, hospitalname, phoneNo, experience FROM doctors WHERE email = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $userInfo = [
                'id' => $user['id'],
                'name' => $user['doctorName'],
                'email' => $user['email'],
                'hospitalType' => $user['hospitalType'],
                'hospitalname' => $user['hospitalname'],
                'phoneNo' => $user['phoneNo'],
                'experience' => $user['experience'],
                'userType' => 'doctor'
            ];
            error_log("Doctor found: " . $user['doctorName']);
        } else {
            error_log("No doctor found with email: $email");
        }
        $stmt->close();
        
    } else if ($userType === 'admin') {
        // Query for admin based on your schema
        $stmt = $conn->prepare("SELECT id, name, email, password, role FROM admins WHERE email = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $userInfo = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'userType' => 'admin'
            ];
            error_log("Admin found: " . $user['name']);
        } else {
            error_log("No admin found with email: $email");
        }
        $stmt->close();
    }

    // Check if user exists
    if (!$user) {
        error_log("User not found for email: $email, userType: $userType");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        error_log("Password verification failed for: $email");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit();
    }

    // Generate session token
    $token = bin2hex(random_bytes(32));
    
    error_log("Login successful for: $email");
    
    // Successful login response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => $userInfo
    ]);

} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
