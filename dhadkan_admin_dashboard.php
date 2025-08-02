<?php
// Start output buffering to prevent any unwanted output
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

// Function to send JSON response
function sendResponse($success, $data = null, $message = '', $httpCode = 200) {
    // Clean any previous output
    if (ob_get_length()) ob_clean();
    
    // Ensure no output has been sent before headers
    if (headers_sent($file, $line)) {
        error_log("Headers already sent in $file on line $line");
    }
    
    // Set additional CORS headers if needed
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json; charset=utf-8');
    }
    
    http_response_code($httpCode);
    
    $response = json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    echo $response;
    
    // End output buffering and flush
    if (ob_get_level()) {
        ob_end_flush();
    }
    
    exit();
}

// Function to validate database connection
function validateConnection() {
    global $conn;
    if (!$conn || $conn->connect_error) {
        sendResponse(false, null, 'Database connection failed', 500);
    }
}

// Validate database connection
validateConnection();

try {
    // Get dashboard statistics
    getDashboardStats();
} catch (Exception $e) {
    error_log("Admin Dashboard API Error: " . $e->getMessage());
    sendResponse(false, null, 'Internal server error: ' . $e->getMessage(), 500);
}

// Function to get comprehensive dashboard statistics
function getDashboardStats() {
    global $conn;
    
    try {
        // CHILDREN STATISTICS
        // 1. Total children screened
        $totalChildrenQuery = "SELECT COUNT(*) as total FROM children";
        $totalChildrenResult = $conn->query($totalChildrenQuery);
        $totalChildren = $totalChildrenResult->fetch_assoc()['total'];
        
        // 2. Children Positive/Suspicious cases (संदिग्ध)
        $childrenPositiveQuery = "SELECT COUNT(*) as positive FROM children WHERE heartStatus = 'संदिग्ध'";
        $childrenPositiveResult = $conn->query($childrenPositiveQuery);
        $childrenPositive = $childrenPositiveResult->fetch_assoc()['positive'];
        
        // 3. Children Healthy cases (संदेह नहीं)
        $childrenHealthyQuery = "SELECT COUNT(*) as healthy FROM children WHERE heartStatus = 'संदेह नहीं'";
        $childrenHealthyResult = $conn->query($childrenHealthyQuery);
        $childrenHealthy = $childrenHealthyResult->fetch_assoc()['healthy'];
        
        // TEACHER STATISTICS
        // 4. Total teachers screened
        $totalTeachersQuery = "SELECT COUNT(*) as total FROM teacher";
        $totalTeachersResult = $conn->query($totalTeachersQuery);
        $totalTeachers = $totalTeachersResult->fetch_assoc()['total'];
        
        // 5. Teachers Positive/Suspicious cases (संदिग्ध)
        $teachersPositiveQuery = "SELECT COUNT(*) as positive FROM teacher WHERE t_heartStatus = 'संदिग्ध'";
        $teachersPositiveResult = $conn->query($teachersPositiveQuery);
        $teachersPositive = $teachersPositiveResult->fetch_assoc()['positive'];
        
        // 6. Teachers Healthy cases (संदेह नहीं)
        $teachersHealthyQuery = "SELECT COUNT(*) as healthy FROM teacher WHERE t_heartStatus = 'संदेह नहीं'";
        $teachersHealthyResult = $conn->query($teachersHealthyQuery);
        $teachersHealthy = $teachersHealthyResult->fetch_assoc()['healthy'];
        
        // EMPLOYEE STATISTICS
        // 7. Total employees screened
        $totalEmployeesQuery = "SELECT COUNT(*) as total FROM employee";
        $totalEmployeesResult = $conn->query($totalEmployeesQuery);
        $totalEmployees = $totalEmployeesResult->fetch_assoc()['total'];
        
        // 8. Employees Positive/Suspicious cases (संदिग्ध)
        $employeesPositiveQuery = "SELECT COUNT(*) as positive FROM employee WHERE e_heartStatus = 'संदिग्ध'";
        $employeesPositiveResult = $conn->query($employeesPositiveQuery);
        $employeesPositive = $employeesPositiveResult->fetch_assoc()['positive'];
        
        // 9. Employees Healthy cases (संदेह नहीं)
        $employeesHealthyQuery = "SELECT COUNT(*) as healthy FROM employee WHERE e_heartStatus = 'संदेह नहीं'";
        $employeesHealthyResult = $conn->query($employeesHealthyQuery);
        $employeesHealthy = $employeesHealthyResult->fetch_assoc()['healthy'];
        
        // COMBINED STATISTICS
        $totalScreenings = $totalChildren + $totalTeachers + $totalEmployees;
        $totalPositiveCases = $childrenPositive + $teachersPositive + $employeesPositive;
        $totalHealthyCases = $childrenHealthy + $teachersHealthy + $employeesHealthy;
        
        // 10. Today's screenings (from all tables)
        $todayChildrenQuery = "SELECT COUNT(*) as today FROM children WHERE DATE(createdat) = CURDATE()";
        $todayTeachersQuery = "SELECT COUNT(*) as today FROM teacher WHERE DATE(t_createdat) = CURDATE()";
        $todayEmployeesQuery = "SELECT COUNT(*) as today FROM employee WHERE DATE(e_createdat) = CURDATE()";
        
        $todayChildren = $conn->query($todayChildrenQuery)->fetch_assoc()['today'];
        $todayTeachers = $conn->query($todayTeachersQuery)->fetch_assoc()['today'];
        $todayEmployees = $conn->query($todayEmployeesQuery)->fetch_assoc()['today'];
        $todayScreenings = $todayChildren + $todayTeachers + $todayEmployees;
        
        // 11. Total doctors
        $totalDoctorsQuery = "SELECT COUNT(*) as total FROM doctors";
        $totalDoctorsResult = $conn->query($totalDoctorsQuery);
        $totalDoctors = $totalDoctorsResult->fetch_assoc()['total'];
        
        // 12. Active doctors (who have done screenings in last 30 days)
        $activeDoctorsQuery = "SELECT COUNT(DISTINCT doctor_id) as active FROM (
            SELECT dr_id as doctor_id FROM children WHERE createdat >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            UNION
            SELECT t_dr_id as doctor_id FROM teacher WHERE t_createdat >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            UNION
            SELECT e_dr_id as doctor_id FROM employee WHERE e_createdat >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ) as combined_doctors";
        $activeDoctorsResult = $conn->query($activeDoctorsQuery);
        $activeDoctors = $activeDoctorsResult->fetch_assoc()['active'];
        
        // 13. This week's screenings (from all tables)
        $thisWeekChildrenQuery = "SELECT COUNT(*) as thisWeek FROM children WHERE YEARWEEK(createdat, 1) = YEARWEEK(CURDATE(), 1)";
        $thisWeekTeachersQuery = "SELECT COUNT(*) as thisWeek FROM teacher WHERE YEARWEEK(t_createdat, 1) = YEARWEEK(CURDATE(), 1)";
        $thisWeekEmployeesQuery = "SELECT COUNT(*) as thisWeek FROM employee WHERE YEARWEEK(e_createdat, 1) = YEARWEEK(CURDATE(), 1)";
        
        $thisWeekChildren = $conn->query($thisWeekChildrenQuery)->fetch_assoc()['thisWeek'];
        $thisWeekTeachers = $conn->query($thisWeekTeachersQuery)->fetch_assoc()['thisWeek'];
        $thisWeekEmployees = $conn->query($thisWeekEmployeesQuery)->fetch_assoc()['thisWeek'];
        $thisWeekScreenings = $thisWeekChildren + $thisWeekTeachers + $thisWeekEmployees;
        
        // 14. This month's screenings (from all tables)
        $thisMonthChildrenQuery = "SELECT COUNT(*) as thisMonth FROM children WHERE YEAR(createdat) = YEAR(CURDATE()) AND MONTH(createdat) = MONTH(CURDATE())";
        $thisMonthTeachersQuery = "SELECT COUNT(*) as thisMonth FROM teacher WHERE YEAR(t_createdat) = YEAR(CURDATE()) AND MONTH(t_createdat) = MONTH(CURDATE())";
        $thisMonthEmployeesQuery = "SELECT COUNT(*) as thisMonth FROM employee WHERE YEAR(e_createdat) = YEAR(CURDATE()) AND MONTH(e_createdat) = MONTH(CURDATE())";
        
        $thisMonthChildren = $conn->query($thisMonthChildrenQuery)->fetch_assoc()['thisMonth'];
        $thisMonthTeachers = $conn->query($thisMonthTeachersQuery)->fetch_assoc()['thisMonth'];
        $thisMonthEmployees = $conn->query($thisMonthEmployeesQuery)->fetch_assoc()['thisMonth'];
        $thisMonthScreenings = $thisMonthChildren + $thisMonthTeachers + $thisMonthEmployees;
        
        // 15. Gender-wise statistics (combined from all tables)
        $genderStatsQuery = "SELECT 
                                'total' as category,
                                gender,
                                COUNT(*) as count,
                                SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy
                             FROM (
                                SELECT gender, heartStatus FROM children
                                UNION ALL
                                SELECT t_gender as gender, t_heartStatus as heartStatus FROM teacher
                                UNION ALL
                                SELECT e_gender as gender, e_heartStatus as heartStatus FROM employee
                             ) as combined_data
                             GROUP BY gender";
        $genderStatsResult = $conn->query($genderStatsQuery);
        $genderStats = [];
        while ($row = $genderStatsResult->fetch_assoc()) {
            $genderStats[] = [
                'gender' => $row['gender'],
                'total' => (int)$row['count'],
                'suspicious' => (int)$row['suspicious'],
                'healthy' => (int)$row['healthy'],
                'suspiciousPercentage' => $row['count'] > 0 ? round(($row['suspicious'] / $row['count']) * 100, 2) : 0
            ];
        }
        
        // 16. Age group statistics (using children data for main chart)
        $ageGroupQuery = "SELECT 
                            CASE 
                                WHEN age BETWEEN 0 AND 5 THEN '0-5 वर्ष'
                                WHEN age BETWEEN 6 AND 10 THEN '6-10 वर्ष'
                                WHEN age BETWEEN 11 AND 15 THEN '11-15 वर्ष'
                                WHEN age BETWEEN 16 AND 18 THEN '16-18 वर्ष'
                                ELSE 'अन्य'
                            END as ageGroup,
                            COUNT(*) as total,
                            SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                            SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy
                          FROM children 
                          GROUP BY ageGroup 
                          ORDER BY 
                            CASE 
                                WHEN ageGroup = '0-5 वर्ष' THEN 1
                                WHEN ageGroup = '6-10 वर्ष' THEN 2
                                WHEN ageGroup = '11-15 वर्ष' THEN 3
                                WHEN ageGroup = '16-18 वर्ष' THEN 4
                                ELSE 5
                            END";
        $ageGroupResult = $conn->query($ageGroupQuery);
        $ageGroups = [];
        while ($row = $ageGroupResult->fetch_assoc()) {
            $ageGroups[] = [
                'ageGroup' => $row['ageGroup'],
                'total' => (int)$row['total'],
                'suspicious' => (int)$row['suspicious'],
                'healthy' => (int)$row['healthy'],
                'suspiciousPercentage' => $row['total'] > 0 ? round(($row['suspicious'] / $row['total']) * 100, 2) : 0
            ];
        }
        
        // 17. Monthly trends (last 6 months) - combined from all tables
        $monthlyTrendsQuery = "SELECT 
                                DATE_FORMAT(createdat, '%Y-%m') as month,
                                DATE_FORMAT(createdat, '%M %Y') as monthName,
                                COUNT(*) as total,
                                SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy
                              FROM (
                                SELECT createdat, heartStatus FROM children WHERE createdat >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                UNION ALL
                                SELECT t_createdat as createdat, t_heartStatus as heartStatus FROM teacher WHERE t_createdat >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                UNION ALL
                                SELECT e_createdat as createdat, e_heartStatus as heartStatus FROM employee WHERE e_createdat >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                              ) as combined_data
                              GROUP BY month, monthName
                              ORDER BY month DESC";
        $monthlyTrendsResult = $conn->query($monthlyTrendsQuery);
        $monthlyTrends = [];
        while ($row = $monthlyTrendsResult->fetch_assoc()) {
            $monthlyTrends[] = [
                'month' => $row['month'],
                'monthName' => $row['monthName'],
                'total' => (int)$row['total'],
                'suspicious' => (int)$row['suspicious'],
                'healthy' => (int)$row['healthy'],
                'suspiciousPercentage' => $row['total'] > 0 ? round(($row['suspicious'] / $row['total']) * 100, 2) : 0
            ];
        }
        
        // 18. Top performing doctors (combined screenings from all tables)
        $topDoctorsQuery = "SELECT 
                                d.id,
                                d.doctorName,
                                d.hospitalname,
                                (COALESCE(c_count.total, 0) + COALESCE(t_count.total, 0) + COALESCE(e_count.total, 0)) as totalScreenings,
                                (COALESCE(c_count.suspicious, 0) + COALESCE(t_count.suspicious, 0) + COALESCE(e_count.suspicious, 0)) as suspiciousFound,
                                (COALESCE(c_count.healthy, 0) + COALESCE(t_count.healthy, 0) + COALESCE(e_count.healthy, 0)) as healthyFound,
                                GREATEST(
                                    COALESCE(c_count.lastScreening, '1970-01-01'),
                                    COALESCE(t_count.lastScreening, '1970-01-01'),
                                    COALESCE(e_count.lastScreening, '1970-01-01')
                                ) as lastScreening
                            FROM doctors d
                            LEFT JOIN (
                                SELECT dr_id, 
                                       COUNT(*) as total,
                                       SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                       SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy,
                                       MAX(createdat) as lastScreening
                                FROM children GROUP BY dr_id
                            ) c_count ON d.id = c_count.dr_id
                            LEFT JOIN (
                                SELECT t_dr_id, 
                                       COUNT(*) as total,
                                       SUM(CASE WHEN t_heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                       SUM(CASE WHEN t_heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy,
                                       MAX(t_createdat) as lastScreening
                                FROM teacher GROUP BY t_dr_id
                            ) t_count ON d.id = t_count.t_dr_id
                            LEFT JOIN (
                                SELECT e_dr_id, 
                                       COUNT(*) as total,
                                       SUM(CASE WHEN e_heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                       SUM(CASE WHEN e_heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy,
                                       MAX(e_createdat) as lastScreening
                                FROM employee GROUP BY e_dr_id
                            ) e_count ON d.id = e_count.e_dr_id
                            HAVING totalScreenings > 0
                            ORDER BY totalScreenings DESC
                            LIMIT 5";
        $topDoctorsResult = $conn->query($topDoctorsQuery);
        $topDoctors = [];
        while ($row = $topDoctorsResult->fetch_assoc()) {
            $successRate = $row['totalScreenings'] > 0 ? 
                round(($row['healthyFound'] / $row['totalScreenings']) * 100, 2) : 0;
            
            $topDoctors[] = [
                'doctorId' => (int)$row['id'],
                'doctorName' => $row['doctorName'],
                'hospitalName' => $row['hospitalname'],
                'totalScreenings' => (int)$row['totalScreenings'],
                'suspiciousFound' => (int)$row['suspiciousFound'],
                'healthyFound' => (int)$row['healthyFound'],
                'successRate' => $successRate,
                'lastScreening' => $row['lastScreening'] && $row['lastScreening'] != '1970-01-01' ? 
                    date('d/m/Y', strtotime($row['lastScreening'])) : null
            ];
        }
        
        // 19. Recent children data for charts (keeping children only for legacy compatibility)
        $recentChildrenQuery = "SELECT 
                                    c.id,
                                    c.name,
                                    c.age,
                                    c.fatherName as parentName,
                                    c.mobileNo as phone,
                                    c.heartStatus,
                                    c.createdat,
                                    d.doctorName,
                                    CASE 
                                        WHEN c.heartStatus = 'संदेह नहीं' THEN 'स्वस्थ'
                                        WHEN c.heartStatus = 'संदिग्ध' THEN 'असामान्य'
                                        ELSE 'अज्ञात'
                                    END as status
                                FROM children c
                                LEFT JOIN doctors d ON c.dr_id = d.id
                                ORDER BY c.createdat DESC
                                LIMIT 100";
        $recentChildrenResult = $conn->query($recentChildrenQuery);
        $recentChildren = [];
        while ($row = $recentChildrenResult->fetch_assoc()) {
            $recentChildren[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'age' => (int)$row['age'],
                'parentName' => $row['parentName'],
                'phone' => $row['phone'],
                'status' => $row['status'],
                'heartStatus' => $row['heartStatus'],
                'doctorName' => $row['doctorName'],
                'screeningDate' => date('d/m/Y', strtotime($row['createdat']))
            ];
        }
        
        // 20. Calculate percentages (based on combined data)
        $healthyPercentage = $totalScreenings > 0 ? round(($totalHealthyCases / $totalScreenings) * 100, 2) : 0;
        $suspiciousPercentage = $totalScreenings > 0 ? round(($totalPositiveCases / $totalScreenings) * 100, 2) : 0;
        
        // 21. Weekly comparison (this week vs last week) - combined from all tables
        $lastWeekChildrenQuery = "SELECT COUNT(*) as lastWeek FROM children WHERE YEARWEEK(createdat, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
        $lastWeekTeachersQuery = "SELECT COUNT(*) as lastWeek FROM teacher WHERE YEARWEEK(t_createdat, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
        $lastWeekEmployeesQuery = "SELECT COUNT(*) as lastWeek FROM employee WHERE YEARWEEK(e_createdat, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
        
        $lastWeekChildren = $conn->query($lastWeekChildrenQuery)->fetch_assoc()['lastWeek'];
        $lastWeekTeachers = $conn->query($lastWeekTeachersQuery)->fetch_assoc()['lastWeek'];
        $lastWeekEmployees = $conn->query($lastWeekEmployeesQuery)->fetch_assoc()['lastWeek'];
        $lastWeekScreenings = $lastWeekChildren + $lastWeekTeachers + $lastWeekEmployees;
        
        $weeklyGrowth = $lastWeekScreenings > 0 ? 
            round((($thisWeekScreenings - $lastWeekScreenings) / $lastWeekScreenings) * 100, 2) : 0;
        
        // Compile all statistics
        $dashboardData = [
            // Children statistics
            'totalChildrenScreened' => (int)$totalChildren,
            'childrenPositiveCases' => (int)$childrenPositive,
            'childrenHealthyCases' => (int)$childrenHealthy,
            
            // Teacher statistics  
            'totalTeachersScreened' => (int)$totalTeachers,
            'teachersPositiveCases' => (int)$teachersPositive,
            'teachersHealthyCases' => (int)$teachersHealthy,
            
            // Employee statistics
            'totalEmployeesScreened' => (int)$totalEmployees,
            'employeesPositiveCases' => (int)$employeesPositive,
            'employeesHealthyCases' => (int)$employeesHealthy,
            
            // Combined statistics
            'totalScreenings' => (int)$totalScreenings,
            'totalPositiveCases' => (int)$totalPositiveCases,
            'totalHealthyCases' => (int)$totalHealthyCases,
            'todayScreenings' => (int)$todayScreenings,
            'totalDoctors' => (int)$totalDoctors,
            'activeDoctors' => (int)$activeDoctors,
            
            // Time-based statistics
            'thisWeekScreenings' => (int)$thisWeekScreenings,
            'thisMonthScreenings' => (int)$thisMonthScreenings,
            'lastWeekScreenings' => (int)$lastWeekScreenings,
            'weeklyGrowth' => $weeklyGrowth,
            
            // Percentages
            'healthyPercentage' => $healthyPercentage,
            'suspiciousPercentage' => $suspiciousPercentage,
            
            // Detailed breakdowns
            'genderStats' => $genderStats,
            'ageGroups' => $ageGroups,
            'monthlyTrends' => $monthlyTrends,
            'topDoctors' => $topDoctors,
            'recentChildren' => $recentChildren,
            
            // Additional insights
            'averageAge' => getAverageAge($conn),
            'mostActiveHospital' => getMostActiveHospital($conn),
            'screeningsByDay' => getScreeningsByDay($conn)
        ];
        
        sendResponse(true, $dashboardData, 'Dashboard statistics retrieved successfully');
        
    } catch (Exception $e) {
        throw new Exception("Error fetching dashboard stats: " . $e->getMessage());
    }
}

// Helper function to get average age
function getAverageAge($conn) {
    $query = "SELECT AVG(age) as avgAge FROM children WHERE age IS NOT NULL";
    $result = $conn->query($query);
    $avg = $result->fetch_assoc()['avgAge'];
    return $avg ? round($avg, 1) : 0;
}

// Helper function to get most active hospital
function getMostActiveHospital($conn) {
    $query = "SELECT 
                d.hospitalname,
                COUNT(c.id) as screenings
              FROM doctors d
              LEFT JOIN children c ON d.id = c.dr_id
              WHERE d.hospitalname IS NOT NULL AND d.hospitalname != ''
              GROUP BY d.hospitalname
              ORDER BY screenings DESC
              LIMIT 1";
    $result = $conn->query($query);
    $hospital = $result->fetch_assoc();
    return $hospital ? [
        'name' => $hospital['hospitalname'],
        'screenings' => (int)$hospital['screenings']
    ] : null;
}

// Helper function to get screenings by day of week
function getScreeningsByDay($conn) {
    $query = "SELECT 
                DAYNAME(createdat) as dayName,
                DAYOFWEEK(createdat) as dayNumber,
                COUNT(*) as screenings
              FROM children 
              WHERE createdat >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY dayName, dayNumber
              ORDER BY dayNumber";
    $result = $conn->query($query);
    $days = [];
    while ($row = $result->fetch_assoc()) {
        $days[] = [
            'day' => $row['dayName'],
            'screenings' => (int)$row['screenings']
        ];
    }
    return $days;
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>
