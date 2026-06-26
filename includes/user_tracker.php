<?php
/**
 * KESA Learn - User Activity & Device Tracking Helper
 * Handles user session tracking, device detection, and activity logging
 */

class UserTracker {
    private $db;
    private $userId;
    private $sessionId;
    
    public function __construct($db, $userId = null) {
        $this->db = $db;
        $this->userId = $userId;
    }
    
    /**
     * Get client IP address
     */
    public static function getClientIP(): string {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For can have multiple)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
    
    /**
     * Get location data from IP using free API
     */
    public static function getLocationFromIP(string $ip): array {
        $location = [
            'country' => null,
            'region' => null,
            'city' => null,
            'timezone' => null,
            'isp' => null
        ];
        
        // Skip for localhost/private IPs
        if ($ip === '127.0.0.1' || $ip === '0.0.0.0' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            return $location;
        }
        
        try {
            // Using ip-api.com (free, no API key required, 45 requests per minute)
            $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,timezone,isp");
            if ($response) {
                $data = json_decode($response, true);
                if ($data && $data['status'] === 'success') {
                    $location['country'] = $data['country'] ?? null;
                    $location['region'] = $data['regionName'] ?? null;
                    $location['city'] = $data['city'] ?? null;
                    $location['timezone'] = $data['timezone'] ?? null;
                    $location['isp'] = $data['isp'] ?? null;
                }
            }
        } catch (Exception $e) {
            // Silently fail - location is optional
        }
        
        return $location;
    }
    
    /**
     * Parse user agent to extract device info
     */
    public static function parseUserAgent(string $userAgent = null): array {
        $userAgent = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        $info = [
            'device_type' => 'desktop',
            'device_name' => null,
            'os_name' => 'Unknown',
            'os_version' => null,
            'browser_name' => 'Unknown',
            'browser_version' => null,
            'user_agent' => $userAgent
        ];
        
        if (empty($userAgent)) {
            return $info;
        }
        
        // Detect device type
        if (preg_match('/Mobile|Android.*Mobile|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent)) {
            $info['device_type'] = 'mobile';
        } elseif (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $userAgent)) {
            $info['device_type'] = 'tablet';
        }
        
        // Detect OS
        $osPatterns = [
            'Windows 11' => '/Windows NT 10.*Win64/i',
            'Windows 10' => '/Windows NT 10/i',
            'Windows 8.1' => '/Windows NT 6.3/i',
            'Windows 8' => '/Windows NT 6.2/i',
            'Windows 7' => '/Windows NT 6.1/i',
            'macOS' => '/Mac OS X ([0-9_]+)/i',
            'iOS' => '/iPhone OS ([0-9_]+)|iPad.*OS ([0-9_]+)/i',
            'Android' => '/Android ([0-9.]+)/i',
            'Linux' => '/Linux/i',
            'Chrome OS' => '/CrOS/i'
        ];
        
        foreach ($osPatterns as $os => $pattern) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $info['os_name'] = $os;
                if (isset($matches[1])) {
                    $info['os_version'] = str_replace('_', '.', $matches[1]);
                }
                break;
            }
        }
        
        // Detect browser
        $browserPatterns = [
            'Edge' => '/Edg\/([0-9.]+)/i',
            'Chrome' => '/Chrome\/([0-9.]+)/i',
            'Firefox' => '/Firefox\/([0-9.]+)/i',
            'Safari' => '/Version\/([0-9.]+).*Safari/i',
            'Opera' => '/OPR\/([0-9.]+)|Opera\/([0-9.]+)/i',
            'IE' => '/MSIE ([0-9.]+)|Trident.*rv:([0-9.]+)/i'
        ];
        
        foreach ($browserPatterns as $browser => $pattern) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $info['browser_name'] = $browser;
                $info['browser_version'] = $matches[1] ?? $matches[2] ?? null;
                break;
            }
        }
        
        // Detect device name
        if (preg_match('/iPhone/', $userAgent)) {
            $info['device_name'] = 'iPhone';
        } elseif (preg_match('/iPad/', $userAgent)) {
            $info['device_name'] = 'iPad';
        } elseif (preg_match('/Macintosh/', $userAgent)) {
            $info['device_name'] = 'Mac';
        } elseif (preg_match('/Samsung|SM-[A-Z0-9]+/i', $userAgent, $m)) {
            $info['device_name'] = 'Samsung';
        } elseif (preg_match('/Pixel/i', $userAgent)) {
            $info['device_name'] = 'Google Pixel';
        } elseif (preg_match('/OnePlus/i', $userAgent)) {
            $info['device_name'] = 'OnePlus';
        } elseif (preg_match('/Windows/', $userAgent)) {
            $info['device_name'] = 'Windows PC';
        }
        
        return $info;
    }
    
    /**
     * Invalidate all other sessions for this user (single device login)
     */
    public function invalidateOtherSessions(int $userId): int {
        try {
            // Mark all existing sessions as logged out
            $stmt = $this->db->prepare("
                UPDATE user_sessions 
                SET is_active = 0, 
                    logout_at = NOW(),
                    logout_reason = 'logged_in_from_another_device'
                WHERE user_id = ? AND is_active = 1
            ");
            $stmt->execute([$userId]);
            $count = $stmt->rowCount();
            
            // Also delete remember tokens to force logout on other devices
            $this->db->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
            
            return $count;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Create or update user session
     */
    public function createSession(int $userId): ?int {
        $this->userId = $userId;
        
        $ip = self::getClientIP();
        $location = self::getLocationFromIP($ip);
        $device = self::parseUserAgent();
        $token = bin2hex(random_bytes(32));
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_sessions 
                (user_id, session_token, ip_address, country, region, city, timezone, isp,
                 device_type, device_name, os_name, os_version, browser_name, browser_version, 
                 user_agent, language)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId, $token, $ip,
                $location['country'], $location['region'], $location['city'], 
                $location['timezone'], $location['isp'],
                $device['device_type'], $device['device_name'], 
                $device['os_name'], $device['os_version'],
                $device['browser_name'], $device['browser_version'],
                $device['user_agent'],
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
            ]);
            
            $this->sessionId = $this->db->lastInsertId();
            $_SESSION['tracking_session_id'] = $this->sessionId;
            
            // Update user stats
            $this->updateUserStats($userId, $ip, $location, $device);
            
            return $this->sessionId;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Update user stats on login/visit
     */
    private function updateUserStats(int $userId, string $ip, array $location, array $device, bool $isSignup = false): void {
        try {
            // Check if stats record exists
            $check = $this->db->prepare("SELECT user_id FROM user_stats WHERE user_id = ?");
            $check->execute([$userId]);
            
            if ($check->fetch()) {
                // Update existing
                $stmt = $this->db->prepare("
                    UPDATE user_stats SET 
                        total_visits = total_visits + 1,
                        last_visit_at = NOW(),
                        last_ip_address = ?,
                        last_country = ?,
                        last_city = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$ip, $location['country'], $location['city'], $userId]);
            } else {
                // Create new
                $stmt = $this->db->prepare("
                    INSERT INTO user_stats 
                    (user_id, total_visits, last_visit_at, last_ip_address, last_country, last_city,
                     registration_ip, registration_country, registration_city, registration_device, 
                     registration_browser, registration_os)
                    VALUES (?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $ip, $location['country'], $location['city'],
                    $ip, $location['country'], $location['city'],
                    $device['device_name'] ?? $device['device_type'],
                    $device['browser_name'],
                    $device['os_name']
                ]);
            }
        } catch (Exception $e) {
            // Silently fail
        }
    }
    
    /**
     * Record login attempt
     */
    public function recordLogin(int $userId, string $type = 'login', bool $success = true, string $failReason = null): void {
        $ip = self::getClientIP();
        $location = self::getLocationFromIP($ip);
        $device = self::parseUserAgent();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_login_history 
                (user_id, login_type, ip_address, country, city, device_info, browser_info, success, failure_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId, $type, $ip, $location['country'], $location['city'],
                ($device['device_name'] ?? $device['device_type']) . ' - ' . $device['os_name'],
                $device['browser_name'] . ' ' . $device['browser_version'],
                $success ? 1 : 0, $failReason
            ]);
        } catch (Exception $e) {
            // Silently fail
        }
    }
    
    /**
     * Record user signup with device info
     */
    public function recordSignup(int $userId): void {
        $ip = self::getClientIP();
        $location = self::getLocationFromIP($ip);
        $device = self::parseUserAgent();
        
        // Create initial user stats
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_stats 
                (user_id, total_visits, last_visit_at, last_ip_address, last_country, last_city,
                 registration_ip, registration_country, registration_city, registration_device, 
                 registration_browser, registration_os)
                VALUES (?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE total_visits = total_visits
            ");
            $stmt->execute([
                $userId, $ip, $location['country'], $location['city'],
                $ip, $location['country'], $location['city'],
                $device['device_name'] ?? $device['device_type'],
                $device['browser_name'],
                $device['os_name']
            ]);
        } catch (Exception $e) {
            // Silently fail
        }
        
        // Record as signup login
        $this->recordLogin($userId, 'signup', true);
    }
    
    /**
     * Log user activity
     */
    public function logActivity(int $userId, string $actionType, string $actionDetails = null, array $metadata = null): void {
        $sessionId = $_SESSION['tracking_session_id'] ?? null;
        $ip = self::getClientIP();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_activity_log 
                (user_id, session_id, action_type, action_details, page_url, referrer_url, ip_address, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId, $sessionId, $actionType, $actionDetails,
                $_SERVER['REQUEST_URI'] ?? null,
                $_SERVER['HTTP_REFERER'] ?? null,
                $ip,
                $metadata ? json_encode($metadata) : null
            ]);
            
            // Update stats counters
            if ($actionType === 'page_view') {
                $this->db->prepare("UPDATE user_stats SET total_page_views = total_page_views + 1 WHERE user_id = ?")->execute([$userId]);
            } elseif ($actionType === 'download') {
                $this->db->prepare("UPDATE user_stats SET total_downloads = total_downloads + 1 WHERE user_id = ?")->execute([$userId]);
            } elseif ($actionType === 'event_registration') {
                $this->db->prepare("UPDATE user_stats SET total_events_registered = total_events_registered + 1 WHERE user_id = ?")->execute([$userId]);
            }
        } catch (Exception $e) {
            // Silently fail
        }
    }
    
    /**
     * Update session activity timestamp
     */
    public function updateActivity(): void {
        $sessionId = $_SESSION['tracking_session_id'] ?? null;
        if ($sessionId) {
            try {
                $this->db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE id = ?")->execute([$sessionId]);
            } catch (Exception $e) {}
        }
    }
    
    /**
     * End user session (logout)
     */
    public function endSession(): void {
        $sessionId = $_SESSION['tracking_session_id'] ?? null;
        if ($sessionId) {
            try {
                $this->db->prepare("UPDATE user_sessions SET is_active = 0, logged_out_at = NOW() WHERE id = ?")->execute([$sessionId]);
            } catch (Exception $e) {}
        }
    }
    
    /**
     * Get user's complete activity data for admin view
     */
    public static function getUserActivityData($db, int $userId): array {
        $data = [
            'stats' => null,
            'sessions' => [],
            'login_history' => [],
            'recent_activity' => []
        ];
        
        try {
            // Get stats
            $stmt = $db->prepare("SELECT * FROM user_stats WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get sessions (last 20)
            $stmt = $db->prepare("
                SELECT * FROM user_sessions 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $data['sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get login history (last 30)
            $stmt = $db->prepare("
                SELECT * FROM user_login_history 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 30
            ");
            $stmt->execute([$userId]);
            $data['login_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get recent activity (last 50)
            $stmt = $db->prepare("
                SELECT * FROM user_activity_log 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$userId]);
            $data['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Tables might not exist yet
        }
        
        return $data;
    }
}

// Global helper functions for easy access
function trackUserActivity(int $userId, string $action, string $details = null, array $metadata = null): void {
    try {
        $db = getDB();
        $tracker = new UserTracker($db);
        $tracker->logActivity($userId, $action, $details, $metadata);
    } catch (Exception $e) {}
}

function trackPageView(int $userId, string $pageName = null): void {
    trackUserActivity($userId, 'page_view', $pageName ?? ($_SERVER['REQUEST_URI'] ?? 'Unknown'));
}
