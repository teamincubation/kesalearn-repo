<?php
// Cleanup script to remove expired password reset tokens
// Run this periodically via cron job or manually

require_once '../config/database.php';

try {
    // Delete expired tokens
    $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR used = 1");
    $deleted = $stmt->execute();
    $count = $stmt->rowCount();
    
    echo "Cleanup completed. Removed $count expired/used tokens.\n";
    
} catch(PDOException $e) {
    echo "Error during cleanup: " . $e->getMessage() . "\n";
}
?>
