
<?php
/**
 * Activity Tracking API Endpoint
 * Receives activity data from client-side tracking and logs it to database
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Error reporting
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user-tracking.php';

try {
    // Get user ID from session if logged in
    $userId = $_SESSION['user_id'] ?? null;
    
    // Parse incoming data - handle both JSON and FormData
    $data = null;
    
    // Try JSON first
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        $data = json_decode($input, true);
    }
    
    // If no JSON data, try POST parameters
    if (!$data && !empty($_POST['data'])) {
        $data = json_decode($_POST['data'], true);
    }
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No tracking data received']);
        exit;
    }
    
    // If user is logged in, log the activity
    if ($userId) {
        $result = logUserActivity(
            $userId,
            $data['activity_type'] ?? 'click',
            $data['element_type'] ?? null,
            $data['element_id'] ?? null,
            $data['element_text'] ?? null,
            $data['page_url'] ?? null
        );
        
        if ($result) {
            http_response_code(200);
            echo json_encode(['status' => 'success', 'message' => 'Activity tracked']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to track activity']);
        }
    } else {
        // For anonymous users, still log for analytics
        error_log(sprintf(
            "Anonymous activity: %s | Element: %s (%s) | Page: %s | IP: %s",
            $data['activity_type'] ?? 'unknown',
            $data['element_type'] ?? 'unknown',
            $data['element_id'] ?? 'no-id',
            $data['page_url'] ?? $_SERVER['REQUEST_URI'],
            $_SERVER['REMOTE_ADDR']
        ));
        
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Activity noted']);
    }
    
} catch (Exception $e) {
    error_log("Activity tracking error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error', 'debug' => $e->getMessage()]);
}
exit;

