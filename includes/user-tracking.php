<?php
/**
 * User Tracking Functions
 * Tracks user location, device, browser, and activity information
 */

/**
 * Get user's IP address (handles proxies and IPv6)
 */
function getUserIp() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP']; // Cloudflare
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]); // First IP in list
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
        return $_SERVER['HTTP_X_FORWARDED'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
        return $_SERVER['HTTP_FORWARDED'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}

/**
 * Get geolocation data from IP using free API
 */
function getGeoLocationData($ip = null) {
    if (!$ip) {
        $ip = getUserIp();
    }
    
    $geoData = [
        'ip_address' => $ip,
        'country' => 'Unknown',
        'region' => 'Unknown',
        'isp' => 'Unknown',
        'as_name' => 'Unknown'
    ];
    
    // Try to get geolocation from IP (using free service)
    // Using ip-api.com free tier (no API key needed, limited to 45 requests/minute)
    $cacheKey = 'geo_' . md5($ip);
    
    // Check if we have cached data
    $cachedData = apcu_fetch($cacheKey);
    if ($cachedData !== false) {
        return array_merge($geoData, $cachedData);
    }
    
    try {
        // Use free IP API
        $url = "http://ip-api.com/json/{$ip}?fields=country,region,isp,as";
        $context = stream_context_create([
            'http' => [
                'timeout' => 2, // 2 second timeout
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'KESA-Learn'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['status']) && $data['status'] === 'success') {
                $geoData['country'] = $data['country'] ?? 'Unknown';
                $geoData['region'] = $data['region'] ?? 'Unknown';
                $geoData['isp'] = $data['isp'] ?? 'Unknown';
                $geoData['as'] = $data['as'] ?? 'Unknown';
                
                // Cache for 7 days
                apcu_store($cacheKey, array_diff_key($geoData, array_flip(['ip_address'])), 604800);
            }
        }
    } catch (Exception $e) {
        error_log("Geolocation fetch error: " . $e->getMessage());
    }
    
    return $geoData;
}

/**
 * Parse user agent to get device, OS, and browser information
 */
function parseUserAgent($userAgent = null) {
    if (!$userAgent) {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    $deviceData = [
        'device_type' => 'Desktop',
        'os' => 'Unknown',
        'browser' => 'Unknown'
    ];
    
    // Detect device type
    if (preg_match('/mobile|android|iphone|ipad|phone/i', $userAgent)) {
        if (preg_match('/ipad/i', $userAgent)) {
            $deviceData['device_type'] = 'Tablet';
        } else {
            $deviceData['device_type'] = 'Mobile';
        }
    } elseif (preg_match('/tablet|ipad|kindle/i', $userAgent)) {
        $deviceData['device_type'] = 'Tablet';
    }
    
    // Detect Operating System
    if (preg_match('/windows nt 10/i', $userAgent)) {
        $deviceData['os'] = 'Windows 10';
    } elseif (preg_match('/windows nt 11/i', $userAgent)) {
        $deviceData['os'] = 'Windows 11';
    } elseif (preg_match('/windows/i', $userAgent)) {
        $deviceData['os'] = 'Windows';
    } elseif (preg_match('/iphone|ios/i', $userAgent)) {
        $deviceData['os'] = 'iOS';
    } elseif (preg_match('/android/i', $userAgent)) {
        $deviceData['os'] = 'Android';
    } elseif (preg_match('/mac|macintosh|macos/i', $userAgent)) {
        $deviceData['os'] = 'macOS';
    } elseif (preg_match('/linux/i', $userAgent)) {
        $deviceData['os'] = 'Linux';
    }
    
    // Detect Browser
    if (preg_match('/chrome/i', $userAgent) && !preg_match('/chromium/i', $userAgent)) {
        $deviceData['browser'] = 'Chrome';
    } elseif (preg_match('/safari/i', $userAgent)) {
        $deviceData['browser'] = 'Safari';
    } elseif (preg_match('/firefox/i', $userAgent)) {
        $deviceData['browser'] = 'Firefox';
    } elseif (preg_match('/edge/i', $userAgent)) {
        $deviceData['browser'] = 'Edge';
    } elseif (preg_match('/opera|opr/i', $userAgent)) {
        $deviceData['browser'] = 'Opera';
    } elseif (preg_match('/msie|trident/i', $userAgent)) {
        $deviceData['browser'] = 'Internet Explorer';
    } else {
        $deviceData['browser'] = 'Other';
    }
    
    return $deviceData;
}

/**
 * Update user tracking information on login/signup
 */
function updateUserTracking($userId) {
    global $db;
    
    if (!$userId || !$db) {
        return false;
    }
    
    try {
        $ipAddress = getUserIp();
        $geoData = getGeoLocationData($ipAddress);
        $deviceData = parseUserAgent();
        
        // Update user tracking columns
        $stmt = $db->prepare("
            UPDATE users SET
                ip_address = ?,
                country = ?,
                region = ?,
                isp = ?,
                as_name = ?,
                device_type = ?,
                os = ?,
                browser = ?,
                last_activity = NOW(),
                last_login = NOW(),
                visit_count = visit_count + 1
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $ipAddress,
            $geoData['country'],
            $geoData['region'],
            $geoData['isp'],
            $geoData['as_name'] ?? $geoData['as'] ?? 'Unknown',
            $deviceData['device_type'],
            $deviceData['os'],
            $deviceData['browser'],
            $userId
        ]);
    } catch (Exception $e) {
        error_log("User tracking update error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user tracking information for display
 */
function getUserTrackingInfo($userId) {
    global $db;
    
    if (!$userId || !$db) {
        return null;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                ip_address,
                country,
                region,
                isp,
                as_name,
                device_type,
                os,
                browser,
                created_at as registered_date,
                last_activity,
                visit_count,
                last_login
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get user tracking info error: " . $e->getMessage());
        return null;
    }
}

/**
 * Log user activity (click, page visit, action, etc.)
 * Called on every user interaction to track what they did
 */
function logUserActivity($userId, $activityType = 'click', $elementType = null, $elementId = null, $elementText = null, $pageUrl = null) {
    global $db;
    
    if (!$userId || !$db) {
        return false;
    }
    
    try {
        if (!$pageUrl) {
            $pageUrl = $_SERVER['REQUEST_URI'] ?? '';
        }
        
        $ipAddress = getUserIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Insert activity log
        $stmt = $db->prepare("
            INSERT INTO user_activity_log 
            (user_id, activity_type, page_url, element_type, element_id, element_text, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $userId,
            $activityType,
            $pageUrl,
            $elementType,
            $elementId,
            $elementText,
            $ipAddress,
            $userAgent
        ]);
        
        // Update user's last activity timestamp
        if ($result) {
            updateUserLastActivity($userId);
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update user's last activity timestamp
 */
function updateUserLastActivity($userId) {
    global $db;
    
    if (!$userId || !$db) {
        return false;
    }
    
    try {
        $stmt = $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        return $stmt->execute([$userId]);
    } catch (Exception $e) {
        error_log("Update last activity error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user's activity history
 */
function getUserActivityHistory($userId, $limit = 50) {
    global $db;
    
    if (!$userId || !$db) {
        return [];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                action_type as activity_type,
                page_url,
                action_type as element_type,
                id as element_id,
                action_details as element_text,
                created_at as timestamp
            FROM user_activity_log
            WHERE user_id = ? AND deleted_at IS NULL
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get activity history error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user visit history from user_visits table
 */
function getUserVisitHistory($userId, $limit = 50) {
    global $db;
    
    if (!$userId || !$db) {
        return [];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                ip_address,
                country,
                state,
                city,
                device_name,
                device_type,
                os,
                browser,
                network_type,
                isp,
                as_name,
                visited_at
            FROM user_visits
            WHERE user_id = ?
            ORDER BY visited_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get visit history error: " . $e->getMessage());
        return [];
    }
}

/**
 * Format datetime to 12-hour format with AM/PM
 */
function formatTime12Hour($datetime) {
    if (!$datetime) {
        return 'N/A';
    }
    
    try {
        $date = new DateTime($datetime);
        return $date->format('M d, g:i A'); // e.g., "Apr 02, 4:51 PM"
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Delete user activity older than specified days
 */
function deleteOldUserActivity($days = 90) {
    global $db;
    
    if (!$db) {
        return false;
    }
    
    try {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        // Get count of activities to delete
        $countStmt = $db->prepare("
            SELECT COUNT(*) as count FROM user_activity_log 
            WHERE created_at < ? AND deleted_at IS NULL
        ");
        $countStmt->execute([$cutoffDate]);
        $countResult = $countStmt->fetch();
        $deletedCount = $countResult['count'] ?? 0;
        
        if ($deletedCount > 0) {
            // Mark activities as deleted instead of hard delete (for audit trail)
            $stmt = $db->prepare("
                UPDATE user_activity_log 
                SET deleted_at = NOW(), deleted_by_admin = FALSE
                WHERE created_at < ? AND deleted_at IS NULL
            ");
            $result = $stmt->execute([$cutoffDate]);
            
            if ($result) {
                error_log("Deleted $deletedCount old activities (older than $days days)");
            }
            
            return true;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Delete old activity error: " . $e->getMessage());
        return false;
    }
}

/**
 * Manually delete user activity by admin
 */
function deleteUserActivityByAdmin($userId, $adminId, $daysBefore = null) {
    global $db;
    
    if (!$userId || !$adminId || !$db) {
        return false;
    }
    
    try {
        $query = "
            UPDATE user_activity_log 
            SET deleted_at = NOW(), deleted_by_admin = TRUE
            WHERE user_id = ? AND deleted_at IS NULL
        ";
        
        $params = [$userId];
        
        if ($daysBefore) {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysBefore days"));
            $query .= " AND created_at < ?";
            $params[] = $cutoffDate;
        }
        
        $stmt = $db->prepare($query);
        $result = $stmt->execute($params);
        
        if ($result) {
            error_log("Admin $adminId deleted activities for user $userId");
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Delete user activity by admin error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user activity statistics
 */
function getUserActivityStats($userId) {
    global $db;
    
    if (!$userId || !$db) {
        return null;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_activities,
                COUNT(DISTINCT DATE(created_at)) as active_days,
                COUNT(DISTINCT action_type) as different_activities,
                MAX(created_at) as last_activity,
                COUNT(CASE WHEN action_type = 'click' THEN 1 END) as total_clicks
            FROM user_activity_log
            WHERE user_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get activity stats error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get user's active and recent sessions
 */
function getUserSessions($userId, $limit = 20) {
    global $db;
    
    if (!$userId || !$db) {
        return [];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                ip_address,
                country,
                region,
                city,
                device_type,
                device_name,
                os_name,
                os_version,
                browser_name,
                browser_version,
                screen_resolution,
                is_active,
                created_at,
                last_activity,
                logged_out_at
            FROM user_sessions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get user sessions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user login history
 */
function getUserLoginHistory($userId, $limit = 20) {
    global $db;
    
    if (!$userId || !$db) {
        return [];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                login_type,
                ip_address,
                country,
                city,
                device_info,
                browser_info,
                success,
                failure_reason,
                created_at
            FROM user_login_history
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get login history error: " . $e->getMessage());
        return [];
    }
}
