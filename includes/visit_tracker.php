<?php
/**
 * User Visit Tracking
 * Tracks user visits with detailed device/location info
 */

function trackUserVisit() {
    if (!isLoggedIn()) return;
    
    $userId = $_SESSION['user_id'];
    
    // Only track once per session
    if (isset($_SESSION['visit_tracked'])) return;
    
    $db = getDB();
    
    // Get IP address
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    
    // Parse user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceInfo = parseUserAgent($userAgent);
    
    // Get geolocation from IP (using free service)
    $geoData = getIPGeolocation($ip);
    
    try {
        // Check if user_visits table exists
        $tableExists = $db->query("SHOW TABLES LIKE 'user_visits'")->rowCount() > 0;
        
        if ($tableExists) {
            // Insert visit record with ISP and AS Name
            $stmt = $db->prepare("
                INSERT INTO user_visits (user_id, ip_address, country, state, city, device_name, device_type, os, browser, user_agent, network_type, isp, as_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $ip,
                $geoData['country'],
                $geoData['state'],
                $geoData['city'],
                $deviceInfo['device_name'],
                $deviceInfo['device_type'],
                $deviceInfo['os'],
                $deviceInfo['browser'],
                substr($userAgent, 0, 1000),
                $deviceInfo['network_type'],
                $geoData['isp'] ?? null,
                $geoData['as'] ?? null
            ]);
        }
        
        // Update user's last visit info
        $updateStmt = $db->prepare("UPDATE users SET last_visit_at = NOW(), last_ip = ?, visit_count = visit_count + 1 WHERE id = ?");
        $updateStmt->execute([$ip, $userId]);
        
        $_SESSION['visit_tracked'] = true;
        
    } catch (Exception $e) {
        // Silently fail - don't break the site for tracking errors
        error_log("Visit tracking error: " . $e->getMessage());
    }
}

function parseUserAgent($ua) {
    $result = [
        'device_name' => 'Unknown',
        'device_type' => 'desktop',
        'os' => 'Unknown',
        'browser' => 'Unknown',
        'network_type' => null
    ];
    
    // Detect device type
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $ua)) {
        $result['device_type'] = 'tablet';
    } elseif (preg_match('/(mobile|iphone|ipod|android.*mobile|blackberry|opera mini|windows phone)/i', $ua)) {
        $result['device_type'] = 'mobile';
    }
    
    // Detect OS
    if (preg_match('/Windows NT 10/i', $ua)) {
        $result['os'] = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6.3/i', $ua)) {
        $result['os'] = 'Windows 8.1';
    } elseif (preg_match('/Windows NT 6.2/i', $ua)) {
        $result['os'] = 'Windows 8';
    } elseif (preg_match('/Windows NT 6.1/i', $ua)) {
        $result['os'] = 'Windows 7';
    } elseif (preg_match('/Mac OS X ([0-9_]+)/i', $ua, $m)) {
        $result['os'] = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/iPhone OS ([0-9_]+)/i', $ua, $m)) {
        $result['os'] = 'iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Android ([0-9.]+)/i', $ua, $m)) {
        $result['os'] = 'Android ' . $m[1];
    } elseif (preg_match('/Linux/i', $ua)) {
        $result['os'] = 'Linux';
    }
    
    // Detect browser
    if (preg_match('/Edge\/([0-9.]+)/i', $ua, $m)) {
        $result['browser'] = 'Edge ' . $m[1];
    } elseif (preg_match('/Edg\/([0-9.]+)/i', $ua, $m)) {
        $result['browser'] = 'Edge ' . $m[1];
    } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua, $m)) {
        $result['browser'] = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua, $m)) {
        $result['browser'] = 'Firefox ' . $m[1];
    } elseif (preg_match('/Safari\/([0-9.]+)/i', $ua) && preg_match('/Version\/([0-9.]+)/i', $ua, $m)) {
        $result['browser'] = 'Safari ' . $m[1];
    } elseif (preg_match('/Opera|OPR\/([0-9.]+)/i', $ua, $m)) {
        $result['browser'] = 'Opera ' . ($m[1] ?? '');
    }
    
    // Detect device name from common patterns
    if (preg_match('/iPhone/i', $ua)) {
        $result['device_name'] = 'Apple iPhone';
    } elseif (preg_match('/iPad/i', $ua)) {
        $result['device_name'] = 'Apple iPad';
    } elseif (preg_match('/Macintosh/i', $ua)) {
        $result['device_name'] = 'Apple Mac';
    } elseif (preg_match('/(SM-[A-Z0-9]+)/i', $ua, $m)) {
        $result['device_name'] = 'Samsung ' . $m[1];
    } elseif (preg_match('/(Pixel [0-9a-zA-Z]+)/i', $ua, $m)) {
        $result['device_name'] = 'Google ' . $m[1];
    } elseif (preg_match('/(Redmi|POCO|Mi) ([^;)]+)/i', $ua, $m)) {
        $result['device_name'] = 'Xiaomi ' . $m[1] . ' ' . trim($m[2]);
    } elseif (preg_match('/OnePlus ([^;)]+)/i', $ua, $m)) {
        $result['device_name'] = 'OnePlus ' . trim($m[1]);
    } elseif (preg_match('/Windows/i', $ua)) {
        $result['device_name'] = 'Windows PC';
    }
    
    return $result;
}

function getIPGeolocation($ip) {
    $result = [
        'country' => null,
        'state' => null,
        'city' => null,
        'isp' => null,
        'as' => null
    ];
    
    // Skip for localhost
    if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'])) {
        return $result;
    }
    
    // Use ip-api.com free service (no API key needed, 45 req/min limit)
    $cacheKey = 'geo_' . md5($ip);
    
    // Check session cache first
    if (isset($_SESSION[$cacheKey])) {
        return $_SESSION[$cacheKey];
    }
    
    try {
        // Fetch geolocation data including ISP and AS information
        $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,isp,as", false, stream_context_create([
            'http' => ['timeout' => 2]
        ]));
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && $data['status'] === 'success') {
                $result = [
                    'country' => $data['country'] ?? null,
                    'state' => $data['regionName'] ?? null,
                    'city' => $data['city'] ?? null,
                    'isp' => $data['isp'] ?? null,
                    'as' => $data['as'] ?? null
                ];
                // Cache in session
                $_SESSION[$cacheKey] = $result;
            }
        }
    } catch (Exception $e) {
        // Silently fail
        error_log("Geolocation fetch error: " . $e->getMessage());
    }
    
    return $result;
}

// Auto-track on include
trackUserVisit();
