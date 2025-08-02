<?php
// Database configuration for XAMPP MySQL
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'dhadkan';

// Create connection with error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    
    // Set charset to utf8mb4 for proper Unicode support
    $conn->set_charset("utf8mb4");
    
    // Optional: Log successful connection (for debugging)
    // error_log("Database connected successfully to: " . $dbname);
    
} catch (mysqli_sql_exception $e) {
    // Log the error
    error_log("Database connection failed: " . $e->getMessage());
    
    // Return JSON error for API calls
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Database connection failed',
            'error' => $e->getMessage()
        ]);
        exit();
    } else {
        // For direct PHP file access
        die("Database connection failed: " . $e->getMessage());
    }
}

// Optional: Test connection function
function testConnection() {
    global $conn;
    if ($conn->ping()) {
        return true;
    }
    return false;
}
?>
