<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'dhadkan_db.php';

try {
    echo "<h1>📊 DHADKAN DB - Table Dump</h1>";

    // Get all tables
    $tablesResult = $conn->query("SHOW TABLES");
    if (!$tablesResult || $tablesResult->num_rows === 0) {
        die("<p>❌ No tables found in the database.</p>");
    }

    while ($row = $tablesResult->fetch_array()) {
        $tableName = $row[0];
        echo "<h2>🧾 Table: <code>$tableName</code></h2>";

        // Get table data
        $dataResult = $conn->query("SELECT * FROM $tableName");
        if ($dataResult && $dataResult->num_rows > 0) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            
            // Table header
            echo "<tr>";
            while ($field = $dataResult->fetch_field()) {
                echo "<th>" . htmlspecialchars($field->name) . "</th>";
            }
            echo "</tr>";

            // Table rows
            while ($dataRow = $dataResult->fetch_assoc()) {
                echo "<tr>";
                foreach ($dataRow as $cell) {
                    echo "<td>" . htmlspecialchars((string)$cell) . "</td>";
                }
                echo "</tr>";
            }

            echo "</table><br>";
        } else {
            echo "<p>⚠ No data in this table.</p>";
        }
    }

} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>