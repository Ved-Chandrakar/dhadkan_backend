<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'db.php';

// Demo admin data
$demoAdmins = [
    [
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'admin123',
        'role' => 'admin'
    ]
];

echo "<!DOCTYPE html>
<html lang='hi'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Demo Admin Data Insert</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f8f9fa; font-weight: bold; }
        h1 { color: #FF9933; text-align: center; }
        .password-info { font-size: 12px; color: #666; margin-top: 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>धड़कन - Demo Admin Data Insert</h1>";

try {
    echo "<div class='info'><strong>📋 Admin Data को Database में Insert कर रहे हैं...</strong></div>";
    
    // Check if admins table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'admins'");
    if ($tableCheck->num_rows == 0) {
        echo "<div class='error'>❌ Error: 'admins' table नहीं मिली। पहले database setup करें।</div>";
        exit();
    }
    
    echo "<h3>🔐 Password Hashing Test:</h3>";
    $testPassword = 'admin123';
    $testHash = password_hash($testPassword, PASSWORD_DEFAULT);
    echo "<div class='info'>";
    echo "<strong>Plain Password:</strong> " . $testPassword . "<br>";
    echo "<strong>Hashed Password:</strong> " . substr($testHash, 0, 30) . "...<br>";
    echo "<strong>Verification Test:</strong> " . (password_verify($testPassword, $testHash) ? "✅ Success" : "❌ Failed");
    echo "</div>";
    
    echo "<h3>📊 Inserting Admin Data:</h3>";
    
    $insertedCount = 0;
    $skippedCount = 0;
    
    foreach ($demoAdmins as $admin) {
        // Check if admin already exists
        $checkStmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
        $checkStmt->bind_param("s", $admin['email']);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<div class='info'>⚠️ Admin already exists: " . htmlspecialchars($admin['email']) . " - Skipped</div>";
            $skippedCount++;
            $checkStmt->close();
            continue;
        }
        $checkStmt->close();
        
        // Hash the password
        $hashedPassword = password_hash($admin['password'], PASSWORD_DEFAULT);
        
        // Insert admin
        $insertStmt = $conn->prepare("INSERT INTO admins (name, email, password, role) VALUES (?, ?, ?, ?)");
        $insertStmt->bind_param("ssss", $admin['name'], $admin['email'], $hashedPassword, $admin['role']);
        
        if ($insertStmt->execute()) {
            echo "<div class='success'>✅ Admin inserted successfully: " . htmlspecialchars($admin['name']) . " (" . htmlspecialchars($admin['email']) . ")</div>";
            echo "<div class='password-info'>Plain Password: " . $admin['password'] . " | Hash: " . substr($hashedPassword, 0, 20) . "...</div>";
            $insertedCount++;
        } else {
            echo "<div class='error'>❌ Error inserting admin: " . htmlspecialchars($admin['name']) . " - " . $insertStmt->error . "</div>";
        }
        $insertStmt->close();
    }
    
    echo "<h3>📈 Summary:</h3>";
    echo "<div class='info'>";
    echo "<strong>✅ Inserted:</strong> " . $insertedCount . " admins<br>";
    echo "<strong>⚠️ Skipped:</strong> " . $skippedCount . " admins (already exist)<br>";
    echo "<strong>📊 Total Processed:</strong> " . count($demoAdmins) . " admins";
    echo "</div>";
    
    // Show all admins in database
    echo "<h3>👥 All Admins in Database:</h3>";
    $allAdmins = $conn->query("SELECT id, name, email, role, created_at FROM admins ORDER BY id");
    
    if ($allAdmins && $allAdmins->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Created At</th><th>Password Test</th></tr>";
        
        while ($row = $allAdmins->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['role']) . "</td>";
            echo "<td>" . ($row['created_at'] ?? 'N/A') . "</td>";
            
            // Test password for demo accounts
            $testResult = "N/A";
            foreach ($demoAdmins as $demoAdmin) {
                if ($demoAdmin['email'] === $row['email']) {
                    // Get password hash from database
                    $passStmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
                    $passStmt->bind_param("i", $row['id']);
                    $passStmt->execute();
                    $passResult = $passStmt->get_result();
                    $passData = $passResult->fetch_assoc();
                    $passStmt->close();
                    
                    if ($passData) {
                        $isValid = password_verify($demoAdmin['password'], $passData['password']);
                        $testResult = $isValid ? "✅ " . $demoAdmin['password'] : "❌ Failed";
                    }
                    break;
                }
            }
            echo "<td>" . $testResult . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>❌ No admins found in database</div>";
    }
    
    echo "<h3>🔑 Login Credentials:</h3>";
    echo "<div class='info'>";
    echo "<strong>Use these credentials to login:</strong><br><br>";
    foreach ($demoAdmins as $admin) {
        echo "<strong>📧 " . htmlspecialchars($admin['email']) . "</strong><br>";
        echo "🔒 Password: " . $admin['password'] . "<br>";
        echo "👤 Role: " . $admin['role'] . "<br><br>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Database Error: " . $e->getMessage() . "</div>";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo "</div></body></html>";
?>
