<?php
$host = "localhost"; // Change if using a different server
$username = "root"; // Default XAMPP username
$password = "passdimuna#19"; // Default XAMPP password (empty)
$database = "banking_project_db"; // Change to your actual database name

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
