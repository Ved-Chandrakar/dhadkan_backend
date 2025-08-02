<?php
// Doctor Management API
// Comprehensive backend for DoctorManagement component with full CRUD operations

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set CORS headers first, before any output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'dhadkan_db.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($action);
            break;
        case 'POST':
            handlePostRequest($action);
            break;
        case 'PUT':
            handlePutRequest($action);
            break;
        case 'DELETE':
            handleDeleteRequest($action);
            break;
        default:
            sendErrorResponse(405, 'Method not allowed');
            break;
    }
} catch (Exception $e) {
    error_log("Doctor Management API Error: " . $e->getMessage());
    sendErrorResponse(500, 'Internal server error: ' . $e->getMessage());
}

// Handle GET requests
function handleGetRequest($action) {
    global $conn;
    
    switch ($action) {
        case 'stats':
            getDoctorStats();
            break;
        case 'list':
            getDoctorsList();
            break;
        case 'detail':
            getDoctorDetail();
            break;
        case 'search':
            searchDoctors();
            break;
        default:
            // Default action - return all doctors with stats
            getAllDoctorsWithStats();
            break;
    }
}

// Handle POST requests (Create new doctor)
function handlePostRequest($action) {
    global $conn;
    
    switch ($action) {
        case 'add':
            addNewDoctor();
            break;
        default:
            sendErrorResponse(400, 'Invalid POST action');
            break;
    }
}

// Handle PUT requests (Update doctor)
function handlePutRequest($action) {
    global $conn;
    
    switch ($action) {
        case 'update':
            updateDoctor();
            break;
        default:
            sendErrorResponse(400, 'Invalid PUT action');
            break;
    }
}

// Handle DELETE requests
function handleDeleteRequest($action) {
    global $conn;
    
    switch ($action) {
        case 'delete':
            deleteDoctor();
            break;
        default:
            sendErrorResponse(400, 'Invalid DELETE action');
            break;
    }
}

// Get comprehensive doctor statistics
function getDoctorStats() {
    global $conn;
    
    try {
        // Total doctors
        $totalDoctorsQuery = "SELECT COUNT(*) as total FROM doctors";
        $totalResult = $conn->query($totalDoctorsQuery);
        $totalDoctors = $totalResult->fetch_assoc()['total'];
        
        // Active doctors (who have performed screenings in last 30 days)
        $activeDoctorsQuery = "
            SELECT COUNT(DISTINCT dr_id) as active 
            FROM children 
            WHERE createdat >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        $activeResult = $conn->query($activeDoctorsQuery);
        $activeDoctors = $activeResult->fetch_assoc()['active'];
        
        // Total screenings by all doctors
        $totalScreeningsQuery = "SELECT COUNT(*) as total FROM children";
        $screeningsResult = $conn->query($totalScreeningsQuery);
        $totalScreenings = $screeningsResult->fetch_assoc()['total'];
        
        // Average experience
        $avgExperienceQuery = "SELECT AVG(experience) as avg_exp FROM doctors WHERE experience IS NOT NULL";
        $expResult = $conn->query($avgExperienceQuery);
        $avgExperience = round($expResult->fetch_assoc()['avg_exp'] ?? 0, 1);
        
        // Hospital type distribution
        $hospitalTypesQuery = "
            SELECT hospitalType, COUNT(*) as count 
            FROM doctors 
            WHERE hospitalType IS NOT NULL AND hospitalType != ''
            GROUP BY hospitalType
        ";
        $hospitalTypesResult = $conn->query($hospitalTypesQuery);
        $hospitalTypes = [];
        while ($row = $hospitalTypesResult->fetch_assoc()) {
            $hospitalTypes[] = $row;
        }
        
        // Top performing doctors (by screenings count)
        $topDoctorsQuery = "
            SELECT 
                d.id,
                d.doctorName,
                d.hospitalname,
                COUNT(c.id) as totalScreenings,
                SUM(CASE WHEN c.heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthyFound,
                SUM(CASE WHEN c.heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousFound,
                ROUND((SUM(CASE WHEN c.heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) / COUNT(c.id)) * 100, 2) as successRate,
                MAX(c.createdat) as lastScreening
            FROM doctors d
            LEFT JOIN children c ON d.id = c.dr_id
            GROUP BY d.id, d.doctorName, d.hospitalname
            HAVING totalScreenings > 0
            ORDER BY totalScreenings DESC
            LIMIT 5
        ";
        $topDoctorsResult = $conn->query($topDoctorsQuery);
        $topDoctors = [];
        while ($row = $topDoctorsResult->fetch_assoc()) {
            $row['lastScreening'] = $row['lastScreening'] ? date('d/m/Y', strtotime($row['lastScreening'])) : 'कभी नहीं';
            $topDoctors[] = $row;
        }
        
        // Recent doctor registrations
        $recentDoctorsQuery = "
            SELECT id, doctorName, hospitalname, phoneNo, email, createdAt
            FROM doctors 
            ORDER BY createdAt DESC 
            LIMIT 5
        ";
        $recentResult = $conn->query($recentDoctorsQuery);
        $recentDoctors = [];
        while ($row = $recentResult->fetch_assoc()) {
            $row['createdAt'] = date('d/m/Y', strtotime($row['createdAt']));
            $recentDoctors[] = $row;
        }
        
        // Monthly registration trends (last 6 months)
        $monthlyTrendsQuery = "
            SELECT 
                DATE_FORMAT(createdAt, '%Y-%m') as month,
                DATE_FORMAT(createdAt, '%M %Y') as monthName,
                COUNT(*) as registrations
            FROM doctors 
            WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(createdAt, '%Y-%m')
            ORDER BY month ASC
        ";
        $trendsResult = $conn->query($monthlyTrendsQuery);
        $monthlyTrends = [];
        while ($row = $trendsResult->fetch_assoc()) {
            $monthlyTrends[] = $row;
        }
        
        sendSuccessResponse([
            'totalDoctors' => (int)$totalDoctors,
            'activeDoctors' => (int)$activeDoctors,
            'inactiveDoctors' => (int)$totalDoctors - (int)$activeDoctors,
            'totalScreenings' => (int)$totalScreenings,
            'averageExperience' => $avgExperience,
            'hospitalTypes' => $hospitalTypes,
            'topDoctors' => $topDoctors,
            'recentDoctors' => $recentDoctors,
            'monthlyTrends' => $monthlyTrends,
            'averageScreeningsPerDoctor' => $totalDoctors > 0 ? round($totalScreenings / $totalDoctors, 1) : 0
        ], 'Doctor statistics retrieved successfully');
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error fetching doctor statistics: ' . $e->getMessage());
    }
}

// Get all doctors with complete data
function getAllDoctorsWithStats() {
    global $conn;
    
    try {
        $query = "
            SELECT 
                d.id,
                d.doctorName,
                d.hospitalType,
                d.hospitalname,
                d.phoneNo,
                d.experience,
                d.email,
                d.createdAt,
                d.updatedAt,
                COUNT(c.id) as totalScreenings,
                SUM(CASE WHEN c.heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthyFound,
                SUM(CASE WHEN c.heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousFound,
                MAX(c.createdat) as lastScreening,
                CASE 
                    WHEN MAX(c.createdat) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'सक्रिय'
                    ELSE 'निष्क्रिय'
                END as status
            FROM doctors d
            LEFT JOIN children c ON d.id = c.dr_id
            GROUP BY d.id, d.doctorName, d.hospitalType, d.hospitalname, d.phoneNo, d.experience, d.email, d.createdAt, d.updatedAt
            ORDER BY d.createdAt DESC
        ";
        
        $result = $conn->query($query);
        $doctors = [];
        
        while ($row = $result->fetch_assoc()) {
            // Format data for frontend compatibility
            $doctor = [
                'id' => (int)$row['id'],
                'doctorName' => $row['doctorName'],
                'hospitalType' => $row['hospitalType'] ?? '',
                'hospitalname' => $row['hospitalname'] ?? '',
                'phoneNo' => $row['phoneNo'],
                'experience' => (int)$row['experience'],
                'email' => $row['email'],
                'createdAt' => $row['createdAt'],
                'updatedAt' => $row['updatedAt'],
                'totalScreenings' => (int)$row['totalScreenings'],
                'healthyFound' => (int)$row['healthyFound'],
                'suspiciousFound' => (int)$row['suspiciousFound'],
                'lastScreening' => $row['lastScreening'] ? date('d/m/Y', strtotime($row['lastScreening'])) : 'कभी नहीं',
                'status' => $row['status'],
                // Legacy fields for compatibility
                'name' => $row['doctorName'],
                'specialization' => 'बाल हृदय विशेषज्ञ', // Default specialization
                'hospital' => $row['hospitalname'] ?? 'अज्ञात',
                'phone' => $row['phoneNo'],
                'joiningDate' => date('d/m/Y', strtotime($row['createdAt']))
            ];
            
            $doctors[] = $doctor;
        }
        
        // Send doctors data as response
        sendSuccessResponse($doctors, 'All doctors retrieved successfully');
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error fetching doctors: ' . $e->getMessage());
    }
}

// Get doctors list (simple list for dropdowns)
function getDoctorsList() {
    global $conn;
    
    try {
        $query = "SELECT id, doctorName, hospitalname, email, phoneNo FROM doctors ORDER BY doctorName ASC";
        $result = $conn->query($query);
        $doctors = [];
        
        while ($row = $result->fetch_assoc()) {
            $doctors[] = $row;
        }
        
        sendSuccessResponse($doctors, 'Doctors list retrieved successfully');
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error fetching doctors list: ' . $e->getMessage());
    }
}

// Get single doctor detail
function getDoctorDetail() {
    global $conn;
    
    $doctorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($doctorId <= 0) {
        sendErrorResponse(400, 'Valid doctor ID is required');
        return;
    }
    
    try {
        $query = "
            SELECT 
                d.*,
                COUNT(c.id) as totalScreenings,
                SUM(CASE WHEN c.heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthyFound,
                SUM(CASE WHEN c.heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousFound,
                MAX(c.createdat) as lastScreening
            FROM doctors d
            LEFT JOIN children c ON d.id = c.dr_id
            WHERE d.id = ?
            GROUP BY d.id
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $doctorId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendErrorResponse(404, 'Doctor not found');
            return;
        }
        
        $doctor = $result->fetch_assoc();
        $doctor['lastScreening'] = $doctor['lastScreening'] ? date('d/m/Y H:i', strtotime($doctor['lastScreening'])) : 'कभी नहीं';
        
        // Get recent screenings by this doctor
        $recentScreeningsQuery = "
            SELECT name, age, gender, heartStatus, createdat
            FROM children 
            WHERE dr_id = ? 
            ORDER BY createdat DESC 
            LIMIT 10
        ";
        $stmt2 = $conn->prepare($recentScreeningsQuery);
        $stmt2->bind_param("i", $doctorId);
        $stmt2->execute();
        $screeningsResult = $stmt2->get_result();
        
        $recentScreenings = [];
        while ($row = $screeningsResult->fetch_assoc()) {
            $row['createdat'] = date('d/m/Y', strtotime($row['createdat']));
            $recentScreenings[] = $row;
        }
        
        $doctor['recentScreenings'] = $recentScreenings;
        
        sendSuccessResponse($doctor, 'Doctor details retrieved successfully');
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error fetching doctor details: ' . $e->getMessage());
    }
}

// Search doctors
function searchDoctors() {
    global $conn;
    
    $searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
    $hospitalType = isset($_GET['hospital_type']) ? trim($_GET['hospital_type']) : '';
    
    if (empty($searchTerm) && empty($hospitalType)) {
        getAllDoctorsWithStats();
        return;
    }
    
    try {
        $conditions = [];
        $params = [];
        $types = '';
        
        if (!empty($searchTerm)) {
            $conditions[] = "(d.doctorName LIKE ? OR d.hospitalname LIKE ? OR d.email LIKE ?)";
            $searchPattern = "%$searchTerm%";
            $params[] = $searchPattern;
            $params[] = $searchPattern;
            $params[] = $searchPattern;
            $types .= 'sss';
        }
        
        if (!empty($hospitalType)) {
            $conditions[] = "d.hospitalType = ?";
            $params[] = $hospitalType;
            $types .= 's';
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $query = "
            SELECT 
                d.id,
                d.doctorName,
                d.hospitalType,
                d.hospitalname,
                d.phoneNo,
                d.experience,
                d.email,
                d.createdAt,
                COUNT(c.id) as totalScreenings,
                MAX(c.createdat) as lastScreening
            FROM doctors d
            LEFT JOIN children c ON d.id = c.dr_id
            WHERE $whereClause
            GROUP BY d.id
            ORDER BY d.doctorName ASC
        ";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $doctors = [];
        while ($row = $result->fetch_assoc()) {
            $row['lastScreening'] = $row['lastScreening'] ? date('d/m/Y', strtotime($row['lastScreening'])) : 'कभी नहीं';
            $doctors[] = $row;
        }
        
        sendSuccessResponse($doctors, 'Search results retrieved successfully');
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error searching doctors: ' . $e->getMessage());
    }
}

// Add new doctor
function addNewDoctor() {
    global $conn;
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendErrorResponse(400, 'Invalid JSON input');
        return;
    }
    
    // Validate required fields
    $required = ['doctorName', 'email', 'phoneNo', 'password'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            sendErrorResponse(400, "Field '$field' is required");
            return;
        }
    }
    
    // Validate email format
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        sendErrorResponse(400, 'Invalid email format');
        return;
    }
    
    // Validate phone number (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $input['phoneNo'])) {
        sendErrorResponse(400, 'Phone number must be exactly 10 digits');
        return;
    }
    
    // Validate password length
    if (strlen($input['password']) < 6) {
        sendErrorResponse(400, 'Password must be at least 6 characters long');
        return;
    }
    
    try {
        // Check if email already exists
        $checkEmailQuery = "SELECT id FROM doctors WHERE email = ?";
        $stmt = $conn->prepare($checkEmailQuery);
        $stmt->bind_param("s", $input['email']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            sendErrorResponse(409, 'Doctor with this email already exists');
            return;
        }
        
        // Hash password
        $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
        
        // Prepare insert query
        $insertQuery = "
            INSERT INTO doctors (
                doctorName, 
                hospitalType, 
                hospitalname, 
                phoneNo, 
                experience, 
                email, 
                password, 
                createdAt, 
                updatedAt
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ";
        
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param(
            "ssssiss",
            $input['doctorName'],
            $input['hospitalType'] ?? null,
            $input['hospitalname'] ?? null,
            $input['phoneNo'],
            $input['experience'] ?? 0,
            $input['email'],
            $hashedPassword
        );
        
        if ($stmt->execute()) {
            $newDoctorId = $conn->insert_id;
            
            // Get the newly created doctor
            $getNewDoctorQuery = "SELECT * FROM doctors WHERE id = ?";
            $stmt2 = $conn->prepare($getNewDoctorQuery);
            $stmt2->bind_param("i", $newDoctorId);
            $stmt2->execute();
            $newDoctor = $stmt2->get_result()->fetch_assoc();
            
            // Remove password from response
            unset($newDoctor['password']);
            
            sendSuccessResponse([
                'doctor' => $newDoctor,
                'id' => $newDoctorId
            ], 'Doctor added successfully');
            
        } else {
            sendErrorResponse(500, 'Failed to add doctor: ' . $stmt->error);
        }
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error adding doctor: ' . $e->getMessage());
    }
}

// Update doctor
function updateDoctor() {
    global $conn;
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        sendErrorResponse(400, 'Doctor ID is required');
        return;
    }
    
    $doctorId = (int)$input['id'];
    
    if ($doctorId <= 0) {
        sendErrorResponse(400, 'Valid doctor ID is required');
        return;
    }
    
    try {
        // Check if doctor exists
        $checkQuery = "SELECT id FROM doctors WHERE id = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("i", $doctorId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            sendErrorResponse(404, 'Doctor not found');
            return;
        }
        
        // Build update query dynamically
        $updateFields = [];
        $params = [];
        $types = '';
        
        if (isset($input['doctorName']) && !empty($input['doctorName'])) {
            $updateFields[] = "doctorName = ?";
            $params[] = $input['doctorName'];
            $types .= 's';
        }
        
        if (isset($input['hospitalType'])) {
            $updateFields[] = "hospitalType = ?";
            $params[] = $input['hospitalType'];
            $types .= 's';
        }
        
        if (isset($input['hospitalname'])) {
            $updateFields[] = "hospitalname = ?";
            $params[] = $input['hospitalname'];
            $types .= 's';
        }
        
        if (isset($input['phoneNo']) && !empty($input['phoneNo'])) {
            if (!preg_match('/^[0-9]{10}$/', $input['phoneNo'])) {
                sendErrorResponse(400, 'Phone number must be exactly 10 digits');
                return;
            }
            $updateFields[] = "phoneNo = ?";
            $params[] = $input['phoneNo'];
            $types .= 's';
        }
        
        if (isset($input['experience'])) {
            $updateFields[] = "experience = ?";
            $params[] = (int)$input['experience'];
            $types .= 'i';
        }
        
        if (isset($input['email']) && !empty($input['email'])) {
            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                sendErrorResponse(400, 'Invalid email format');
                return;
            }
            
            // Check if email is already used by another doctor
            $checkEmailQuery = "SELECT id FROM doctors WHERE email = ? AND id != ?";
            $stmt2 = $conn->prepare($checkEmailQuery);
            $stmt2->bind_param("si", $input['email'], $doctorId);
            $stmt2->execute();
            if ($stmt2->get_result()->num_rows > 0) {
                sendErrorResponse(409, 'Email is already used by another doctor');
                return;
            }
            
            $updateFields[] = "email = ?";
            $params[] = $input['email'];
            $types .= 's';
        }
        
        // Handle password update
        if (isset($input['password']) && !empty($input['password'])) {
            if (strlen($input['password']) < 6) {
                sendErrorResponse(400, 'Password must be at least 6 characters long');
                return;
            }
            $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $hashedPassword;
            $types .= 's';
        }
        
        if (empty($updateFields)) {
            sendErrorResponse(400, 'No fields to update');
            return;
        }
        
        // Add updatedAt field
        $updateFields[] = "updatedAt = NOW()";
        
        // Add doctor ID for WHERE clause
        $params[] = $doctorId;
        $types .= 'i';
        
        $updateQuery = "UPDATE doctors SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            // Get updated doctor data
            $getUpdatedQuery = "SELECT * FROM doctors WHERE id = ?";
            $stmt2 = $conn->prepare($getUpdatedQuery);
            $stmt2->bind_param("i", $doctorId);
            $stmt2->execute();
            $updatedDoctor = $stmt2->get_result()->fetch_assoc();
            
            // Remove password from response
            unset($updatedDoctor['password']);
            
            sendSuccessResponse([
                'doctor' => $updatedDoctor
            ], 'Doctor updated successfully');
            
        } else {
            sendErrorResponse(500, 'Failed to update doctor: ' . $stmt->error);
        }
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error updating doctor: ' . $e->getMessage());
    }
}

// Delete doctor
function deleteDoctor() {
    global $conn;
    
    $doctorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($doctorId <= 0) {
        sendErrorResponse(400, 'Valid doctor ID is required');
        return;
    }
    
    try {
        // Check if doctor exists
        $checkQuery = "SELECT doctorName FROM doctors WHERE id = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("i", $doctorId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            sendErrorResponse(404, 'Doctor not found');
            return;
        }
        
        $doctor = $result->fetch_assoc();
        
        // Check if doctor has screenings
        $checkScreeningsQuery = "SELECT COUNT(*) as count FROM children WHERE dr_id = ?";
        $stmt2 = $conn->prepare($checkScreeningsQuery);
        $stmt2->bind_param("i", $doctorId);
        $stmt2->execute();
        $screeningsCount = $stmt2->get_result()->fetch_assoc()['count'];
        
        if ($screeningsCount > 0) {
            sendErrorResponse(409, "Cannot delete doctor {$doctor['doctorName']} as they have $screeningsCount screening records. Please transfer or delete the records first.");
            return;
        }
        
        // Delete doctor
        $deleteQuery = "DELETE FROM doctors WHERE id = ?";
        $stmt3 = $conn->prepare($deleteQuery);
        $stmt3->bind_param("i", $doctorId);
        
        if ($stmt3->execute()) {
            sendSuccessResponse([
                'doctorId' => $doctorId,
                'doctorName' => $doctor['doctorName']
            ], 'Doctor deleted successfully');
        } else {
            sendErrorResponse(500, 'Failed to delete doctor: ' . $stmt3->error);
        }
        
    } catch (Exception $e) {
        sendErrorResponse(500, 'Error deleting doctor: ' . $e->getMessage());
    }
}

// Helper function to send success response
function sendSuccessResponse($data, $message = 'Success') {
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// Helper function to send error response
function sendErrorResponse($code, $message) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
