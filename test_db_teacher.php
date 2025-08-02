<?php
// Simple test to check database connection and teacher table
require_once 'db.php';

header('Content-Type: application/json');

try {
    // Test connection
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    // Test if teacher table exists
    $result = $conn->query("SHOW TABLES LIKE 'teacher'");
    if ($result->num_rows == 0) {
        throw new Exception("Teacher table does not exist");
    }
    
    // Test table structure
    $result = $conn->query("DESCRIBE teacher");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful',
        'teacher_columns' => $columns
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
