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
require_once 'db.php';

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
        // 1. Total children screened
        $totalChildrenQuery = "SELECT COUNT(*) as total FROM children";
        $totalChildrenResult = $conn->query($totalChildrenQuery);
        $totalChildren = $totalChildrenResult->fetch_assoc()['total'];
        
        // 2. Positive/Suspicious cases (संदिग्ध)
        $positiveCasesQuery = "SELECT COUNT(*) as positive FROM children WHERE heartStatus = 'संदिग्ध'";
        $positiveCasesResult = $conn->query($positiveCasesQuery);
        $positiveCases = $positiveCasesResult->fetch_assoc()['positive'];
        
        // 3. Healthy cases (संदेह नहीं)
        $healthyCasesQuery = "SELECT COUNT(*) as healthy FROM children WHERE heartStatus = 'संदेह नहीं'";
        $healthyCasesResult = $conn->query($healthyCasesQuery);
        $healthyCases = $healthyCasesResult->fetch_assoc()['healthy'];
        
        // 4. Today's screenings
        $todayQuery = "SELECT COUNT(*) as today FROM children WHERE DATE(createdat) = CURDATE()";
        $todayResult = $conn->query($todayQuery);
        $todayScreenings = $todayResult->fetch_assoc()['today'];
        
        // 5. Total doctors
        $totalDoctorsQuery = "SELECT COUNT(*) as total FROM doctors";
        $totalDoctorsResult = $conn->query($totalDoctorsQuery);
        $totalDoctors = $totalDoctorsResult->fetch_assoc()['total'];
        
        // 6. Active doctors (who have done screenings in last 30 days)
        $activeDoctorsQuery = "SELECT COUNT(DISTINCT dr_id) as active FROM children WHERE createdat >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $activeDoctorsResult = $conn->query($activeDoctorsQuery);
        $activeDoctors = $activeDoctorsResult->fetch_assoc()['active'];
        
        // 7. This week's screenings
        $thisWeekQuery = "SELECT COUNT(*) as thisWeek FROM children WHERE YEARWEEK(createdat, 1) = YEARWEEK(CURDATE(), 1)";
        $thisWeekResult = $conn->query($thisWeekQuery);
        $thisWeekScreenings = $thisWeekResult->fetch_assoc()['thisWeek'];
        
        // 8. This month's screenings
        $thisMonthQuery = "SELECT COUNT(*) as thisMonth FROM children WHERE YEAR(createdat) = YEAR(CURDATE()) AND MONTH(createdat) = MONTH(CURDATE())";
        $thisMonthResult = $conn->query($thisMonthQuery);
        $thisMonthScreenings = $thisMonthResult->fetch_assoc()['thisMonth'];
        
        // 9. Gender-wise statistics
        $genderStatsQuery = "SELECT 
                                gender,
                                COUNT(*) as count,
                                SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy
                             FROM children 
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
        
        // 10. Age group statistics
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
        
        // 11. Monthly trends (last 6 months)
        $monthlyTrendsQuery = "SELECT 
                                DATE_FORMAT(createdat, '%Y-%m') as month,
                                DATE_FORMAT(createdat, '%M %Y') as monthName,
                                COUNT(*) as total,
                                SUM(CASE WHEN heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspicious,
                                SUM(CASE WHEN heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthy
                              FROM children 
                              WHERE createdat >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
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
        
        // 12. Top performing doctors
        $topDoctorsQuery = "SELECT 
                                d.id,
                                d.doctorName,
                                d.hospitalname,
                                COUNT(c.id) as totalScreenings,
                                SUM(CASE WHEN c.heartStatus = 'संदिग्ध' THEN 1 ELSE 0 END) as suspiciousFound,
                                SUM(CASE WHEN c.heartStatus = 'संदेह नहीं' THEN 1 ELSE 0 END) as healthyFound,
                                MAX(c.createdat) as lastScreening
                            FROM doctors d
                            LEFT JOIN children c ON d.id = c.dr_id
                            GROUP BY d.id, d.doctorName, d.hospitalname
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
                'lastScreening' => $row['lastScreening'] ? date('d/m/Y', strtotime($row['lastScreening'])) : null
            ];
        }
        
        // 13. Recent children data for charts
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
        
        // 14. Calculate percentages
        $healthyPercentage = $totalChildren > 0 ? round(($healthyCases / $totalChildren) * 100, 2) : 0;
        $suspiciousPercentage = $totalChildren > 0 ? round(($positiveCases / $totalChildren) * 100, 2) : 0;
        
        // 15. Weekly comparison (this week vs last week)
        $lastWeekQuery = "SELECT COUNT(*) as lastWeek FROM children WHERE YEARWEEK(createdat, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
        $lastWeekResult = $conn->query($lastWeekQuery);
        $lastWeekScreenings = $lastWeekResult->fetch_assoc()['lastWeek'];
        
        $weeklyGrowth = $lastWeekScreenings > 0 ? 
            round((($thisWeekScreenings - $lastWeekScreenings) / $lastWeekScreenings) * 100, 2) : 0;
        
        // Compile all statistics
        $dashboardData = [
            // Main statistics
            'totalChildrenScreened' => (int)$totalChildren,
            'positiveCases' => (int)$positiveCases,
            'healthyCases' => (int)$healthyCases,
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
