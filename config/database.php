<?php
/**
 * KESA Learn - Database Configuration
 * 
 * Update these credentials with your Hostinger MySQL details.
 * Find them in Hostinger hPanel > Databases > MySQL Databases.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'u806388046_kesawebsite');
define('DB_USER', 'u806388046_kesaweb');
define('DB_PASS', 'Kesa2026IncKesa#admin');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO database connection
 * Uses singleton pattern to reuse connections
 * Sets timezone to IST (Asia/Kolkata) for consistent date/time handling
 */
function getDB(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Set both charset and timezone for MySQL session
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'"
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }
    }
    
    return $pdo;
}
