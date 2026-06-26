<?php
// Database configuration for KESA Learning Certificate System
// Update these with your actual database details

$host = 'localhost';
$dbname = 'u806388046_certificates'; // Replace with your actual database name
$username = 'u806388046_kesalearn'; // Replace with your actual username  
$password = 'Root@2025kesa#'; // Replace with your actual password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
