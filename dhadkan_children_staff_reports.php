<?php
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Clean any previous output
if (ob_get_length()) ob_clean();

// Set CORS headers first, before any output
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

// Include database connection
require_once 'dhadkan_db.php';
// Check if database connection exists
if (!isset($conn)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection not available'
    ]);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : 'getReports';

    switch ($action) {
        case 'getReports':
            getChildrenReports($conn);
            break;
        case 'getStats':
            getReportsStats($conn);
            break;
        case 'getReportDetails':
            getReportDetails($conn);
            break;
        case 'getStaffReports':
            getStaffReports($conn);
            break;
        case 'getStaffStats':
            getStaffStats($conn);
            break;
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action specified'
            ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred',
        'error' => $e->getMessage()
    ]);
}

/**
 * Get all children reports with doctor information
 */
function getChildrenReports($conn) {
    try {
        // Get filter parameters
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $heartStatus = isset($_GET['heartStatus']) ? trim($_GET['heartStatus']) : '';
        $doctorId = isset($_GET['doctorId']) ? (int)$_GET['doctorId'] : 0;

        // Base query with JOIN to get doctor information
        $baseQuery = "
            SELECT 
                c.id,
                c.dr_id,
                c.name,
                c.age,
                c.gender,
                c.fatherName,
                c.mobileNo,
                c.schoolName,
                c.haveAadhar,
                c.haveShramik,
                c.aadharPhoto,
                c.shramikPhoto,
                c.heartStatus,
                c.notes,
                c.createdat as screeningDate,
                d.doctorName,
                d.hospitalname,
                d.hospitalType
            FROM children c
            INNER JOIN doctors d ON c.dr_id = d.id
        ";

        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        $types = '';

        if (!empty($search)) {
            $whereConditions[] = "(c.name LIKE ? OR c.fatherName LIKE ? OR c.schoolName LIKE ?)";
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sss';
        }

        if (!empty($heartStatus)) {
            $whereConditions[] = "c.heartStatus = ?";
            $params[] = $heartStatus;
            $types .= 's';
        }

        if ($doctorId > 0) {
            $whereConditions[] = "c.dr_id = ?";
            $params[] = $doctorId;
            $types .= 'i';
        }

        $whereClause = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM children c INNER JOIN doctors d ON c.dr_id = d.id" . $whereClause;
        $countStmt = $conn->prepare($countQuery);
        
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        
        $countStmt->execute();
        $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];

        // Get paginated data
        $dataQuery = $baseQuery . $whereClause . " ORDER BY c.createdat DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($dataQuery);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $reports[] = [
                'id' => (int)$row['id'],
                'dr_id' => (int)$row['dr_id'],
                'name' => $row['name'],
                'age' => (int)$row['age'],
                'gender' => $row['gender'],
                'fatherName' => $row['fatherName'],
                'mobileNo' => $row['mobileNo'],
                'schoolName' => $row['schoolName'],
                'haveAadhar' => $row['haveAadhar'],
                'haveShramik' => $row['haveShramik'],
                'aadharPhoto' => $row['aadharPhoto'],
                'shramikPhoto' => $row['shramikPhoto'],
                'heartStatus' => $row['heartStatus'],
                'notes' => $row['notes'],
                'screeningDate' => date('d/m/Y', strtotime($row['screeningDate'])),
                'doctorName' => $row['doctorName'],
                'hospitalName' => $row['hospitalname'],
                'hospitalType' => $row['hospitalType'],
                // Legacy fields for compatibility
                'childName' => $row['name'],
                'mobileNumber' => $row['mobileNo'],
                'diseaseFound' => $row['heartStatus'] === 'संदिग्ध',
                'healthStatus' => $row['heartStatus'] === 'संदिग्ध' ? 'असामान्य' : 'स्वस्थ'
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $reports,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => ceil($totalRecords / $limit),
                'totalRecords' => (int)$totalRecords,
                'recordsPerPage' => $limit
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching children reports',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get statistics for children reports
 */
function getReportsStats($conn) {
    try {
        // Get overall statistics
        $statsQuery = "
            SELECT 
                COUNT(*) as totalChildren,
                SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as normalCases,
                SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousCases,
                COUNT(DISTINCT dr_id) as totalDoctors,
                COUNT(DISTINCT schoolName) as totalSchools,
                SUM(CASE WHEN haveAadhar = 'yes' THEN 1 ELSE 0 END) as withAadhar,
                SUM(CASE WHEN haveShramik = 'yes' THEN 1 ELSE 0 END) as withShramik
            FROM children
        ";

        $result = $conn->query($statsQuery);
        $stats = $result->fetch_assoc();

        // Get doctor-wise statistics
        $doctorStatsQuery = "
            SELECT 
                d.id,
                d.doctorName,
                d.hospitalname,
                COUNT(c.id) as totalScreenings,
                SUM(CASE WHEN c.heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousFound
            FROM doctors d
            LEFT JOIN children c ON d.id = c.dr_id
            GROUP BY d.id, d.doctorName, d.hospitalname
            ORDER BY totalScreenings DESC
        ";

        $doctorResult = $conn->query($doctorStatsQuery);
        $doctorStats = [];
        while ($row = $doctorResult->fetch_assoc()) {
            $doctorStats[] = [
                'doctorId' => (int)$row['id'],
                'doctorName' => $row['doctorName'],
                'hospitalName' => $row['hospitalname'],
                'totalScreenings' => (int)$row['totalScreenings'],
                'suspiciousFound' => (int)$row['suspiciousFound']
            ];
        }

        // Get monthly screening trends (last 6 months)
        $trendsQuery = "
            SELECT 
                DATE_FORMAT(createdat, '%Y-%m') as month,
                COUNT(*) as screenings,
                SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious
            FROM children 
            WHERE createdat >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(createdat, '%Y-%m')
            ORDER BY month DESC
        ";

        $trendsResult = $conn->query($trendsQuery);
        $trends = [];
        while ($row = $trendsResult->fetch_assoc()) {
            $trends[] = [
                'month' => $row['month'],
                'screenings' => (int)$row['screenings'],
                'suspicious' => (int)$row['suspicious']
            ];
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'totalChildren' => (int)$stats['totalChildren'],
                'normalCases' => (int)$stats['normalCases'],
                'suspiciousCases' => (int)$stats['suspiciousCases'],
                'totalDoctors' => (int)$stats['totalDoctors'],
                'totalSchools' => (int)$stats['totalSchools'],
                'withAadhar' => (int)$stats['withAadhar'],
                'withShramik' => (int)$stats['withShramik'],
                'aadharPercentage' => $stats['totalChildren'] > 0 ? round(($stats['withAadhar'] / $stats['totalChildren']) * 100, 1) : 0,
                'shramikPercentage' => $stats['totalChildren'] > 0 ? round(($stats['withShramik'] / $stats['totalChildren']) * 100, 1) : 0,
                'suspiciousPercentage' => $stats['totalChildren'] > 0 ? round(($stats['suspiciousCases'] / $stats['totalChildren']) * 100, 1) : 0
            ],
            'doctorStats' => $doctorStats,
            'trends' => $trends
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching statistics',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get detailed information for a specific child report
 */
function getReportDetails($conn) {
    try {
        $childId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($childId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Valid child ID is required'
            ]);
            return;
        }

        $query = "
            SELECT 
                c.*,
                d.doctorName,
                d.hospitalname,
                d.hospitalType,
                d.email as doctorEmail,
                d.phoneNo as doctorPhone
            FROM children c
            INNER JOIN doctors d ON c.dr_id = d.id
            WHERE c.id = ?
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $childId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Child report not found'
            ]);
            return;
        }

        $row = $result->fetch_assoc();

        $childDetails = [
            'id' => (int)$row['id'],
            'dr_id' => (int)$row['dr_id'],
            'name' => $row['name'],
            'age' => (int)$row['age'],
            'gender' => $row['gender'],
            'fatherName' => $row['fatherName'],
            'mobileNo' => $row['mobileNo'],
            'schoolName' => $row['schoolName'],
            'haveAadhar' => $row['haveAadhar'],
            'haveShramik' => $row['haveShramik'],
            'aadharPhoto' => $row['aadharPhoto'],
            'shramikPhoto' => $row['shramikPhoto'],
            'heartStatus' => $row['heartStatus'],
            'notes' => $row['notes'],
            'screeningDate' => date('d/m/Y H:i', strtotime($row['createdat'])),
            'doctorInfo' => [
                'name' => $row['doctorName'],
                'hospital' => $row['hospitalname'],
                'hospitalType' => $row['hospitalType'],
                'email' => $row['doctorEmail'],
                'phone' => $row['doctorPhone']
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => $childDetails
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching child details',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get list of all doctors for filter dropdown
 */
function getDoctorsList($conn) {
    try {
        $query = "
            SELECT 
                d.id,
                d.doctorName,
                d.hospitalname,
                COUNT(c.id) as totalReports
            FROM doctors d
            LEFT JOIN children c ON d.id = c.dr_id
            GROUP BY d.id, d.doctorName, d.hospitalname
            ORDER BY d.doctorName
        ";

        $result = $conn->query($query);
        $doctors = [];

        while ($row = $result->fetch_assoc()) {
            $doctors[] = [
                'id' => (int)$row['id'],
                'name' => $row['doctorName'],
                'hospital' => $row['hospitalname'],
                'totalReports' => (int)$row['totalReports']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $doctors
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching doctors list',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get combined staff reports (teachers and employees) with doctor information
 */
function getStaffReports($conn) {
    try {
        // Get filter parameters
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $heartStatus = isset($_GET['heartStatus']) ? trim($_GET['heartStatus']) : '';
        $doctorId = isset($_GET['doctorId']) ? (int)$_GET['doctorId'] : 0;
        $staffType = isset($_GET['staffType']) ? trim($_GET['staffType']) : ''; // 'teacher' or 'employee'

        // UNION query to combine teacher and employee data
        $baseQuery = "
            (SELECT 
                t.t_id as id,
                t.t_dr_id as dr_id,
                t.t_name as name,
                t.t_age as age,
                t.t_gender as gender,
                t.t_mobileNo as mobileNo,
                t.t_schoolName as schoolName,
                t.t_haveAadhar as haveAadhar,
                t.t_haveShramik as haveShramik,
                t.t_aadharPhoto as aadharPhoto,
                t.t_shramikPhoto as shramikPhoto,
                t.t_heartStatus as heartStatus,
                t.t_notes as notes,
                t.t_createdat as createdat,
                'शिक्षक' as staffType,
                d.doctorName,
                d.hospitalname,
                d.hospitalType
            FROM teacher t
            INNER JOIN doctors d ON t.t_dr_id = d.id)
            UNION ALL
            (SELECT 
                e.e_id as id,
                e.e_dr_id as dr_id,
                e.e_name as name,
                e.e_age as age,
                e.e_gender as gender,
                e.e_mobileNo as mobileNo,
                e.e_schoolName as schoolName,
                e.e_haveAadhar as haveAadhar,
                e.e_haveShramik as haveShramik,
                e.e_aadharPhoto as aadharPhoto,
                e.e_shramikPhoto as shramikPhoto,
                e.e_heartStatus as heartStatus,
                e.e_notes as notes,
                e.e_createdat as createdat,
                'कर्मचारी' as staffType,
                d.doctorName,
                d.hospitalname,
                d.hospitalType
            FROM employee e
            INNER JOIN doctors d ON e.e_dr_id = d.id)
        ";

        // Build WHERE clause for filtering
        $whereConditions = [];
        $havingConditions = [];

        if (!empty($search)) {
            $havingConditions[] = "(name LIKE '%$search%' OR schoolName LIKE '%$search%')";
        }

        if (!empty($heartStatus)) {
            $havingConditions[] = "heartStatus = '$heartStatus'";
        }

        if ($doctorId > 0) {
            $havingConditions[] = "dr_id = $doctorId";
        }

        if (!empty($staffType)) {
            if ($staffType === 'teacher') {
                $havingConditions[] = "staffType = 'शिक्षक'";
            } else if ($staffType === 'employee') {
                $havingConditions[] = "staffType = 'कर्मचारी'";
            }
        }

        // Complete query with filtering and pagination
        $finalQuery = "SELECT * FROM ($baseQuery) as combined_staff";
        
        if (!empty($havingConditions)) {
            $finalQuery .= " WHERE " . implode(' AND ', $havingConditions);
        }

        // Get total count first
        $countQuery = "SELECT COUNT(*) as total FROM ($finalQuery) as count_table";
        $countResult = $conn->query($countQuery);
        $totalRecords = $countResult->fetch_assoc()['total'];

        // Add ordering and pagination
        $finalQuery .= " ORDER BY createdat DESC LIMIT $limit OFFSET $offset";

        $result = $conn->query($finalQuery);

        if (!$result) {
            throw new Exception("Database query error: " . $conn->error);
        }

        $staffReports = [];
        while ($row = $result->fetch_assoc()) {
            $staffReports[] = [
                'id' => (int)$row['id'],
                'dr_id' => (int)$row['dr_id'],
                'name' => $row['name'],
                'age' => (int)$row['age'],
                'gender' => $row['gender'],
                'mobileNo' => $row['mobileNo'],
                'schoolName' => $row['schoolName'],
                'haveAadhar' => $row['haveAadhar'],
                'haveShramik' => $row['haveShramik'],
                'aadharPhoto' => $row['aadharPhoto'],
                'shramikPhoto' => $row['shramikPhoto'],
                'heartStatus' => $row['heartStatus'],
                'notes' => $row['notes'],
                'screeningDate' => date('d/m/Y', strtotime($row['createdat'])),
                'staffType' => $row['staffType'],
                'doctorName' => $row['doctorName'],
                'hospitalName' => $row['hospitalname'],
                'hospitalType' => $row['hospitalType']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $staffReports,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => ceil($totalRecords / $limit),
                'totalRecords' => (int)$totalRecords,
                'recordsPerPage' => $limit
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching staff reports',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Get statistics for staff reports (teachers and employees combined)
 */
function getStaffStats($conn) {
    try {
        // Get teacher statistics
        $teacherStatsQuery = "
            SELECT 
                COUNT(*) as totalTeachers,
                SUM(CASE WHEN t_heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as normalTeachers,
                SUM(CASE WHEN t_heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousTeachers,
                COUNT(DISTINCT t_dr_id) as doctorsWithTeachers,
                COUNT(DISTINCT t_schoolName) as schoolsWithTeachers
            FROM teacher
        ";

        // Get employee statistics
        $employeeStatsQuery = "
            SELECT 
                COUNT(*) as totalEmployees,
                SUM(CASE WHEN e_heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as normalEmployees,
                SUM(CASE WHEN e_heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousEmployees,
                COUNT(DISTINCT e_dr_id) as doctorsWithEmployees,
                COUNT(DISTINCT e_schoolName) as schoolsWithEmployees
            FROM employee
        ";

        $teacherResult = $conn->query($teacherStatsQuery);
        $teacherStats = $teacherResult->fetch_assoc();

        $employeeResult = $conn->query($employeeStatsQuery);
        $employeeStats = $employeeResult->fetch_assoc();

        // Calculate combined statistics
        $totalStaff = $teacherStats['totalTeachers'] + $employeeStats['totalEmployees'];
        $normalStaff = $teacherStats['normalTeachers'] + $employeeStats['normalEmployees'];
        $suspiciousStaff = $teacherStats['suspiciousTeachers'] + $employeeStats['suspiciousEmployees'];

        // Get doctor-wise staff statistics
        $doctorStaffQuery = "
            SELECT 
                d.id,
                d.doctorName,
                d.hospitalname,
                COALESCE(teacher_count, 0) as teacherCount,
                COALESCE(employee_count, 0) as employeeCount,
                COALESCE(teacher_count, 0) + COALESCE(employee_count, 0) as totalStaffCount,
                COALESCE(teacher_suspicious, 0) + COALESCE(employee_suspicious, 0) as suspiciousStaffCount
            FROM doctors d
            LEFT JOIN (
                SELECT 
                    t_dr_id,
                    COUNT(*) as teacher_count,
                    SUM(CASE WHEN t_heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as teacher_suspicious
                FROM teacher 
                GROUP BY t_dr_id
            ) t ON d.id = t.t_dr_id
            LEFT JOIN (
                SELECT 
                    e_dr_id,
                    COUNT(*) as employee_count,
                    SUM(CASE WHEN e_heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as employee_suspicious
                FROM employee 
                GROUP BY e_dr_id
            ) e ON d.id = e.e_dr_id
            WHERE (COALESCE(teacher_count, 0) + COALESCE(employee_count, 0)) > 0
            ORDER BY totalStaffCount DESC
        ";

        $doctorStaffResult = $conn->query($doctorStaffQuery);
        $doctorStaffStats = [];
        while ($row = $doctorStaffResult->fetch_assoc()) {
            $doctorStaffStats[] = [
                'doctorId' => (int)$row['id'],
                'doctorName' => $row['doctorName'],
                'hospitalName' => $row['hospitalname'],
                'teacherCount' => (int)$row['teacherCount'],
                'employeeCount' => (int)$row['employeeCount'],
                'totalStaffCount' => (int)$row['totalStaffCount'],
                'suspiciousStaffCount' => (int)$row['suspiciousStaffCount']
            ];
        }

        echo json_encode([
            'success' => true,
            'stats' => [
                'totalStaff' => (int)$totalStaff,
                'totalTeachers' => (int)$teacherStats['totalTeachers'],
                'totalEmployees' => (int)$employeeStats['totalEmployees'],
                'normalStaff' => (int)$normalStaff,
                'suspiciousStaff' => (int)$suspiciousStaff,
                'normalTeachers' => (int)$teacherStats['normalTeachers'],
                'suspiciousTeachers' => (int)$teacherStats['suspiciousTeachers'],
                'normalEmployees' => (int)$employeeStats['normalEmployees'],
                'suspiciousEmployees' => (int)$employeeStats['suspiciousEmployees'],
                'suspiciousPercentage' => $totalStaff > 0 ? round(($suspiciousStaff / $totalStaff) * 100, 1) : 0,
                'teacherPercentage' => $totalStaff > 0 ? round(($teacherStats['totalTeachers'] / $totalStaff) * 100, 1) : 0,
                'employeePercentage' => $totalStaff > 0 ? round(($employeeStats['totalEmployees'] / $totalStaff) * 100, 1) : 0
            ],
            'doctorStaffStats' => $doctorStaffStats
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching staff statistics',
            'error' => $e->getMessage()
        ]);
    }
}
?>
