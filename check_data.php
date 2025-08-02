<?php
header('Content-Type: text/html; charset=utf-8');

require_once 'db.php';

try {
    echo "<h2>Checking Database Data</h2>";
    
    // Check admins table
    echo "<h3>Admins Table:</h3>";
    $result = $conn->query("SELECT id, name, email, password FROM admins");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Password Hash</th><th>Test Password</th></tr>";
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . substr($row['password'], 0, 30) . "...</td>";
            // Test if password is 'admin123'
            $isValid = password_verify('admin123', $row['password']) ? "✅ Valid" : "❌ Invalid";
            echo "<td>admin123: " . $isValid . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No admins found.<br>";
    }
    
    // Check doctors table
    echo "<h3>Doctors Table:</h3>";
    $result = $conn->query("SELECT id, doctorName, email, password FROM doctors");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Doctor Name</th><th>Email</th><th>Password Hash</th><th>Test Password</th></tr>";
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['doctorName']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . substr($row['password'], 0, 30) . "...</td>";
            // Test if password is 'doctor123'
            $isValid = password_verify('doctor123', $row['password']) ? "✅ Valid" : "❌ Invalid";
            echo "<td>doctor123: " . $isValid . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No doctors found.<br>";
    }
    
    // Test password hashing
    echo "<h3>Password Hash Test:</h3>";
    $testPassword = 'admin123';
    $hash = password_hash($testPassword, PASSWORD_DEFAULT);
    echo "Password: " . $testPassword . "<br>";
    echo "Hash: " . $hash . "<br>";
    echo "Verification: " . (password_verify($testPassword, $hash) ? "✅ Success" : "❌ Failed") . "<br>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
