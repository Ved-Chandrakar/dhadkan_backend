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
require_once 'dhadkan_db.php';

// Get the request method and action
$method = $_SERVER['REQUEST_METHOD'];
$request = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$doctor_id = isset($_REQUEST['doctor_id']) ? intval($_REQUEST['doctor_id']) : 0;

// Response function
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit();
}

// Validate doctor ID
function validateDoctorId($doctor_id) {
    if ($doctor_id <= 0) {
        sendResponse(false, null, 'Invalid doctor ID', 400);
    }
}

try {
    switch ($request) {
        
        // Get doctor profile details
        case 'get_doctor_profile':
            validateDoctorId($doctor_id);
            
            $stmt = $conn->prepare("SELECT id, doctorName, hospitalType, hospitalname, phoneNo, experience, email, createdAt, updatedAt FROM doctors WHERE id = ?");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $doctor = $result->fetch_assoc();
                sendResponse(true, $doctor, 'Doctor profile fetched successfully');
            } else {
                sendResponse(false, null, 'Doctor not found', 404);
            }
            break;
            
        // Get doctor statistics
        case 'get_doctor_stats':
            validateDoctorId($doctor_id);
            
            error_log("Getting stats for doctor ID: " . $doctor_id); // Debug log
            
            // Get total children screened by this doctor
            $stmt = $conn->prepare("SELECT COUNT(*) as totalChildrenScreened FROM children WHERE dr_id = ?");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $total_result = $stmt->get_result();
            $total_count = $total_result->fetch_assoc()['totalChildrenScreened'];
            
            error_log("Total children: " . $total_count); // Debug log
            
            // Get positive cases (संदिग्ध)
            $stmt = $conn->prepare("SELECT COUNT(*) as positiveCases FROM children WHERE dr_id = ? AND heartStatus = 'संदिग्ध'");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $positive_result = $stmt->get_result();
            $positive_count = $positive_result->fetch_assoc()['positiveCases'];
            
            // Get today's screenings
            $stmt = $conn->prepare("SELECT COUNT(*) as todayScreenings FROM children WHERE dr_id = ? AND DATE(createdat) = CURDATE()");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $today_result = $stmt->get_result();
            $today_count = $today_result->fetch_assoc()['todayScreenings'];
            
            // Get this week's reports
            $stmt = $conn->prepare("SELECT COUNT(*) as reportsThisWeek FROM children WHERE dr_id = ? AND YEARWEEK(createdat, 1) = YEARWEEK(CURDATE(), 1)");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $week_result = $stmt->get_result();
            $week_count = $week_result->fetch_assoc()['reportsThisWeek'];
            
            // Get teacher stats
            $stmt = $conn->prepare("SELECT COUNT(*) as totalTeachers FROM teacher WHERE t_dr_id = ?");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $teacher_result = $stmt->get_result();
            $teacher_count = $teacher_result->fetch_assoc()['totalTeachers'];
            
            // Get employee stats
            $stmt = $conn->prepare("SELECT COUNT(*) as totalEmployees FROM employee WHERE e_dr_id = ?");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $employee_result = $stmt->get_result();
            $employee_count = $employee_result->fetch_assoc()['totalEmployees'];
            
            // Get teacher/employee positive cases
            $stmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM teacher WHERE t_dr_id = ? AND t_heartStatus = 'संदिग्ध') +
                    (SELECT COUNT(*) FROM employee WHERE e_dr_id = ? AND e_heartStatus = 'संदिग्ध') as staffPositiveCases
            ");
            $stmt->bind_param("ii", $doctor_id, $doctor_id);
            $stmt->execute();
            $staff_positive_result = $stmt->get_result();
            $staff_positive_count = $staff_positive_result->fetch_assoc()['staffPositiveCases'];
            
            $stats = [
                'totalChildrenScreened' => intval($total_count),
                'positiveCases' => intval($positive_count),
                'todayScreenings' => intval($today_count),
                'reportsThisWeek' => intval($week_count),
                'pendingReports' => 0, // You can add logic for pending reports if needed
                'totalTeachers' => intval($teacher_count),
                'totalEmployees' => intval($employee_count),
                'totalStaff' => intval($teacher_count) + intval($employee_count),
                'staffPositiveCases' => intval($staff_positive_count)
            ];
            
            error_log("Stats calculated: " . json_encode($stats)); // Debug log
            
            sendResponse(true, $stats, 'Statistics fetched successfully');
            break;
            
        // Get children list treated by doctor
        case 'get_children_list':
            validateDoctorId($doctor_id);
            
            error_log("Getting children list for doctor ID: " . $doctor_id); // Debug log
            
            // Get pagination parameters
            $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
            $limit = isset($_REQUEST['limit']) ? min(100, max(1, intval($_REQUEST['limit']))) : 50;
            $offset = ($page - 1) * $limit;
            
            // Get total count for pagination
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM children WHERE dr_id = ?");
            $stmt->bind_param("i", $doctor_id);
            $stmt->execute();
            $count_result = $stmt->get_result();
            $total_records = $count_result->fetch_assoc()['total'];
            
            // Get children list with pagination
            $stmt = $conn->prepare("
                SELECT id, name, age, gender, fatherName, mobileNo, schoolName, 
                       haveAadhar, haveShramik, heartStatus, notes, createdat 
                FROM children 
                WHERE dr_id = ? 
                ORDER BY createdat DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("iii", $doctor_id, $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $children = [];
            while ($row = $result->fetch_assoc()) {
                $children[] = [
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'age' => $row['age'] ? intval($row['age']) : 0, // Handle null age
                    'gender' => $row['gender'],
                    'fatherName' => $row['fatherName'],
                    'mobileNo' => $row['mobileNo'],
                    'schoolName' => $row['schoolName'],
                    'haveAadhar' => $row['haveAadhar'],
                    'haveShramik' => $row['haveShramik'],
                    'heartStatus' => $row['heartStatus'],
                    'notes' => $row['notes'],
                    'createdat' => $row['createdat']
                ];
            }
            
            $response_data = [
                'children' => $children,
                'pagination' => [
                    'current_page' => $page,
                    'total_records' => intval($total_records),
                    'total_pages' => ceil($total_records / $limit),
                    'limit' => $limit
                ]
            ];
            
            error_log("Children list response: " . json_encode($response_data)); // Debug log
            
            sendResponse(true, $response_data, 'Children list fetched successfully');
            break;
            
        // Get teacher and employee combined list
        case 'get_teacher_employee_list':
            validateDoctorId($doctor_id);
            
            error_log("Getting teacher/employee list for doctor ID: " . $doctor_id); // Debug log
            
            // Get pagination parameters
            $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
            $limit = isset($_REQUEST['limit']) ? min(100, max(1, intval($_REQUEST['limit']))) : 50;
            $offset = ($page - 1) * $limit;
            
            // Get total count for pagination (teachers + employees)
            $stmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM teacher WHERE t_dr_id = ?) + 
                    (SELECT COUNT(*) FROM employee WHERE e_dr_id = ?) as total
            ");
            $stmt->bind_param("ii", $doctor_id, $doctor_id);
            $stmt->execute();
            $count_result = $stmt->get_result();
            $total_records = $count_result->fetch_assoc()['total'];
            
            // Get combined teacher and employee list with UNION
            $stmt = $conn->prepare("
                (SELECT 
                    t_id as id, 
                    t_name as name, 
                    t_age as age, 
                    t_gender as gender, 
                    t_mobileNo as mobileNo, 
                    t_schoolName as schoolName,
                    t_haveAadhar as haveAadhar, 
                    t_haveShramik as haveShramik, 
                    t_heartStatus as heartStatus, 
                    t_notes as notes, 
                    t_createdat as createdat,
                    'शिक्षक' as category
                FROM teacher 
                WHERE t_dr_id = ?)
                UNION ALL
                (SELECT 
                    e_id as id, 
                    e_name as name, 
                    e_age as age, 
                    e_gender as gender, 
                    e_mobileNo as mobileNo, 
                    e_schoolName as schoolName,
                    e_haveAadhar as haveAadhar, 
                    e_haveShramik as haveShramik, 
                    e_heartStatus as heartStatus, 
                    e_notes as notes, 
                    e_createdat as createdat,
                    'कर्मचारी' as category
                FROM employee 
                WHERE e_dr_id = ?)
                ORDER BY createdat DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("iiii", $doctor_id, $doctor_id, $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $staff = [];
            while ($row = $result->fetch_assoc()) {
                $staff[] = [
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'age' => $row['age'] ? intval($row['age']) : 0,
                    'gender' => $row['gender'],
                    'mobileNo' => $row['mobileNo'],
                    'schoolName' => $row['schoolName'],
                    'haveAadhar' => $row['haveAadhar'],
                    'haveShramik' => $row['haveShramik'],
                    'heartStatus' => $row['heartStatus'],
                    'notes' => $row['notes'],
                    'createdat' => $row['createdat'],
                    'category' => $row['category']
                ];
            }
            
            $response_data = [
                'staff' => $staff,
                'pagination' => [
                    'current_page' => $page,
                    'total_records' => intval($total_records),
                    'total_pages' => ceil($total_records / $limit),
                    'limit' => $limit
                ]
            ];
            
            error_log("Staff list response: " . json_encode($response_data)); // Debug log
            
            sendResponse(true, $response_data, 'Teacher and employee list fetched successfully');
            break;
            
        // Add new child report
        case 'add_child_report':
            if ($method !== 'POST') {
                sendResponse(false, null, 'Method not allowed', 405);
            }
            
            validateDoctorId($doctor_id);
            
            // Get JSON input
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            $required_fields = ['name', 'gender', 'fatherName', 'mobileNo', 'haveAadhar', 'haveShramik', 'heartStatus'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field]) || empty($input[$field])) {
                    sendResponse(false, null, "Missing required field: $field", 400);
                }
            }
            
            // Validate enum values
            if (!in_array($input['gender'], ['पुरुष', 'महिला'])) {
                sendResponse(false, null, 'Invalid gender value', 400);
            }
            
            if (!in_array($input['haveAadhar'], ['yes', 'no'])) {
                sendResponse(false, null, 'Invalid haveAadhar value', 400);
            }
            
            if (!in_array($input['haveShramik'], ['yes', 'no'])) {
                sendResponse(false, null, 'Invalid haveShramik value', 400);
            }
            
            if (!in_array($input['heartStatus'], ['संदिग्ध', 'संदेह नहीं'])) {
                sendResponse(false, null, 'Invalid heartStatus value', 400);
            }
            
            // Check if mobile number already exists
            $stmt = $conn->prepare("SELECT id FROM children WHERE mobileNo = ?");
            $stmt->bind_param("s", $input['mobileNo']);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                sendResponse(false, null, 'Mobile number already exists', 409);
            }
            
            // Insert new child record
            $stmt = $conn->prepare("
                INSERT INTO children (dr_id, name, age, gender, fatherName, mobileNo, schoolName, 
                                    haveAadhar, haveShramik, aadharPhoto, shramikPhoto, heartStatus, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $age = isset($input['age']) ? intval($input['age']) : null;
            $schoolName = isset($input['schoolName']) ? $input['schoolName'] : null;
            $aadharPhoto = isset($input['aadharPhoto']) ? $input['aadharPhoto'] : null;
            $shramikPhoto = isset($input['shramikPhoto']) ? $input['shramikPhoto'] : null;
            $notes = isset($input['notes']) ? $input['notes'] : null;
            
            $stmt->bind_param("isisssssssss", 
                $doctor_id,
                $input['name'],
                $age,
                $input['gender'],
                $input['fatherName'],
                $input['mobileNo'],
                $schoolName,
                $input['haveAadhar'],
                $input['haveShramik'],
                $aadharPhoto,
                $shramikPhoto,
                $input['heartStatus'],
                $notes
            );
            
            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                sendResponse(true, ['id' => $new_id], 'Child report added successfully', 201);
            } else {
                sendResponse(false, null, 'Failed to add child report', 500);
            }
            break;
            
        // Get all doctors (for admin use)
        case 'get_all_doctors':
            $stmt = $conn->prepare("SELECT id, doctorName, email, hospitalname, phoneNo, experience, createdAt FROM doctors ORDER BY createdAt DESC");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $doctors = [];
            while ($row = $result->fetch_assoc()) {
                $doctors[] = $row;
            }
            
            sendResponse(true, $doctors, 'All doctors fetched successfully');
            break;
            
        // Test connection
        case 'test':
            sendResponse(true, ['timestamp' => date('Y-m-d H:i:s')], 'API is working correctly');
            break;
            
        default:
            sendResponse(false, null, 'Invalid action specified', 400);
            break;
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    sendResponse(false, null, 'Internal server error: ' . $e->getMessage(), 500);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>
