<?php
/**
 * KESA Learn - Activity Tracking Functions
 * Handles certificate downloads, event page views, register clicks, and payment tracking
 */

/**
 * Get user's IP address
 */
function getUserIP(): string {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    return trim($ip);
}

/**
 * Get geolocation data from IP (using free IP geolocation service)
 */
function getGeoLocation(string $ip): array {
    $geoData = [
        'country' => null,
        'city' => null,
        'timezone' => null
    ];
    
    // Only make external call for non-localhost IPs
    if ($ip === '127.0.0.1' || $ip === 'localhost') {
        return $geoData;
    }
    
    try {
        // Using free IP geolocation service (ip-api.com)
        $url = "http://ip-api.com/json/$ip?fields=country,city,timezone";
        $response = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3]]));
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['status']) && $data['status'] === 'success') {
                $geoData['country'] = $data['country'] ?? null;
                $geoData['city'] = $data['city'] ?? null;
                $geoData['timezone'] = $data['timezone'] ?? null;
            }
        }
    } catch (Exception $e) {
        error_log("[v0] Geo lookup failed for IP $ip: " . $e->getMessage());
    }
    
    return $geoData;
}

/**
 * Get device information from user agent
 */
function getDeviceInfo(): string {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    if (preg_match('/mobile|android|iphone|ipad|opera mini/i', $userAgent)) {
        return preg_match('/ipad/i', $userAgent) ? 'Tablet' : 'Mobile';
    }
    return 'Desktop';
}

/**
 * Track IP address for non-authenticated users
 */
function trackUserIP(string $ip, ?int $userId = null): bool {
    try {
        $db = getDB();
        $geoData = getGeoLocation($ip);
        
        $stmt = $db->prepare("
            INSERT INTO user_ip_tracking (ip_address, user_id, country, city, visit_count, first_seen, last_seen)
            VALUES (?, ?, ?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                user_id = COALESCE(VALUES(user_id), user_id),
                last_seen = NOW(),
                visit_count = visit_count + 1
        ");
        
        return $stmt->execute([$ip, $userId, $geoData['country'], $geoData['city']]);
    } catch (Exception $e) {
        error_log("[v0] Error tracking IP: " . $e->getMessage());
        return false;
    }
}

/**
 * Log certificate download
 */
function logCertificateDownload(string $certificateCode, string $fileName, ?int $eventId = null, ?int $userId = null): bool {
    try {
        $db = getDB();
        $ip = getUserIP();
        $geoData = getGeoLocation($ip);
        
        $stmt = $db->prepare("
            INSERT INTO certificate_downloads (user_id, ip_address, certificate_code, event_id, file_name, country, city, device_info, downloaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // If not logged in, track the IP
        if ($userId === null) {
            trackUserIP($ip);
        }
        
        return $stmt->execute([
            $userId,
            $ip,
            $certificateCode,
            $eventId,
            $fileName,
            $geoData['country'],
            $geoData['city'],
            getDeviceInfo()
        ]);
    } catch (Exception $e) {
        error_log("[v0] Error logging certificate download: " . $e->getMessage());
        return false;
    }
}

/**
 * Log event page view
 */
function logEventPageView(int $eventId, ?int $userId = null): bool {
    try {
        $db = getDB();
        $ip = getUserIP();
        $geoData = getGeoLocation($ip);
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO event_page_views (event_id, user_id, ip_address, country, city, device_info, referrer_url, viewed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // If not logged in, track the IP
        if ($userId === null) {
            trackUserIP($ip);
        }
        
        return $stmt->execute([
            $eventId,
            $userId,
            $ip,
            $geoData['country'],
            $geoData['city'],
            getDeviceInfo(),
            $referrer
        ]);
    } catch (Exception $e) {
        error_log("[v0] Error logging event page view: " . $e->getMessage());
        return false;
    }
}

/**
 * Log register button click
 */
function logRegisterClick(int $eventId, ?int $userId = null): bool {
    try {
        $db = getDB();
        $ip = getUserIP();
        $geoData = getGeoLocation($ip);
        
        $stmt = $db->prepare("
            INSERT INTO register_clicks (event_id, user_id, ip_address, country, city, device_info, clicked_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // If not logged in, track the IP
        if ($userId === null) {
            trackUserIP($ip);
        }
        
        return $stmt->execute([
            $eventId,
            $userId,
            $ip,
            $geoData['country'],
            $geoData['city'],
            getDeviceInfo()
        ]);
    } catch (Exception $e) {
        error_log("[v0] Error logging register click: " . $e->getMessage());
        return false;
    }
}

/**
 * Log incomplete payment (user clicked continue to payment but didn't complete)
 */
function logIncompletePayment(int $userId, int $eventId, float $amount, string $paymentMethod, ?int $registrationId = null): bool {
    try {
        $db = getDB();
        $ip = getUserIP();
        $geoData = getGeoLocation($ip);
        
        $stmt = $db->prepare("
            INSERT INTO incomplete_payments (user_id, event_id, registration_id, amount, currency, payment_method, ip_address, country, city, device_info, status, initiated_at)
            VALUES (?, ?, ?, ?, 'INR', ?, ?, ?, ?, ?, 'initiated', NOW())
        ");
        
        return $stmt->execute([
            $userId,
            $eventId,
            $registrationId,
            $amount,
            $paymentMethod,
            $ip,
            $geoData['country'],
            $geoData['city'],
            getDeviceInfo()
        ]);
    } catch (Exception $e) {
        error_log("[v0] Error logging incomplete payment: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark payment as completed
 */
function markPaymentCompleted(int $userId, int $eventId): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            UPDATE incomplete_payments 
            SET status = 'completed', completed_at = NOW() 
            WHERE user_id = ? AND event_id = ? AND status IN ('initiated', 'pending')
            ORDER BY initiated_at DESC LIMIT 1
        ");
        
        return $stmt->execute([$userId, $eventId]);
    } catch (Exception $e) {
        error_log("[v0] Error marking payment completed: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark payment as abandoned (24+ hours without completion)
 */
function markAbandonedPayments(): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            UPDATE incomplete_payments 
            SET status = 'abandoned', abandoned_at = NOW()
            WHERE status IN ('initiated', 'pending') 
            AND initiated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("[v0] Error marking abandoned payments: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get certificate downloads log (admin view)
 */
function getCertificateDownloadsLog(array $filters = [], int $page = 1, int $perPage = 50): array {
    try {
        $db = getDB();
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['ip_address'])) {
            $where[] = 'ip_address = ?';
            $params[] = $filters['ip_address'];
        }
        
        if (!empty($filters['event_id'])) {
            $where[] = 'event_id = ?';
            $params[] = $filters['event_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'downloaded_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'downloaded_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Get total count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM certificate_downloads WHERE $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get paginated results
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare("
            SELECT 
                cd.*,
                u.name, u.email,
                e.title as event_title
            FROM certificate_downloads cd
            LEFT JOIN users u ON cd.user_id = u.id
            LEFT JOIN events e ON cd.event_id = e.id
            WHERE $whereClause
            ORDER BY cd.downloaded_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        
        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'records' => $records
        ];
    } catch (Exception $e) {
        error_log("[v0] Error getting certificate downloads log: " . $e->getMessage());
        return ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
    }
}

/**
 * Get incomplete payments log (users who didn't complete payment)
 */
function getIncompletePaymentsLog(array $filters = [], int $page = 1, int $perPage = 50): array {
    try {
        $db = getDB();
        $where = ['status IN ("initiated", "pending", "abandoned")'];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'ip.user_id = ?';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['event_id'])) {
            $where[] = 'ip.event_id = ?';
            $params[] = $filters['event_id'];
        }
        
        if (!empty($filters['status'])) {
            $where[] = 'ip.status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'ip.initiated_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'ip.initiated_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Get total count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM incomplete_payments ip WHERE $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get paginated results
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare("
            SELECT 
                ip.*,
                u.name, u.email, u.phone,
                e.title as event_title, e.price
            FROM incomplete_payments ip
            LEFT JOIN users u ON ip.user_id = u.id
            LEFT JOIN events e ON ip.event_id = e.id
            WHERE $whereClause
            ORDER BY ip.initiated_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        
        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'records' => $records
        ];
    } catch (Exception $e) {
        error_log("[v0] Error getting incomplete payments log: " . $e->getMessage());
        return ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
    }
}

/**
 * Get event page views log
 */
function getEventPageViewsLog(int $eventId, int $page = 1, int $perPage = 50): array {
    try {
        $db = getDB();
        
        // Get total count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM event_page_views WHERE event_id = ?");
        $countStmt->execute([$eventId]);
        $total = $countStmt->fetchColumn();
        
        // Get paginated results
        $offset = ($page - 1) * $perPage;
        $stmt = $db->prepare("
            SELECT 
                epv.*,
                u.name, u.email
            FROM event_page_views epv
            LEFT JOIN users u ON epv.user_id = u.id
            WHERE epv.event_id = ?
            ORDER BY epv.viewed_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$eventId, $perPage, $offset]);
        $records = $stmt->fetchAll();
        
        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
            'records' => $records
        ];
    } catch (Exception $e) {
        error_log("[v0] Error getting event page views log: " . $e->getMessage());
        return ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
    }
}

/**
 * Find users/IPs that match an IP address
 */
function findUsersByIP(string $ip): array {
    try {
        $db = getDB();
        
        // Find all users and activities from this IP
        $stmt = $db->prepare("
            SELECT DISTINCT 
                u.id, u.name, u.email, u.phone, u.created_at,
                ipt.first_seen, ipt.last_seen, ipt.visit_count
            FROM user_ip_tracking ipt
            LEFT JOIN users u ON ipt.user_id = u.id
            WHERE ipt.ip_address = ?
            ORDER BY ipt.last_seen DESC
        ");
        
        $stmt->execute([$ip]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("[v0] Error finding users by IP: " . $e->getMessage());
        return [];
    }
}
