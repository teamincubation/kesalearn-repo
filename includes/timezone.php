<?php
/**
 * KESA Learn - Timezone Configuration
 * 
 * This file ensures all date/time operations use Indian Standard Time (IST)
 * It should be included early in the application bootstrap.
 * 
 * IST is UTC+05:30 (Asia/Kolkata)
 */

// Set PHP default timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Define timezone constants for use throughout the application
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Kolkata');
}

if (!defined('APP_TIMEZONE_OFFSET')) {
    define('APP_TIMEZONE_OFFSET', '+05:30');
}

/**
 * Get the application DateTimeZone object
 */
function getAppTimezone(): DateTimeZone {
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone(APP_TIMEZONE);
    }
    return $tz;
}

/**
 * Get current DateTime in IST
 */
function now(): DateTime {
    return new DateTime('now', getAppTimezone());
}

/**
 * Get current timestamp string for MySQL (Y-m-d H:i:s format)
 */
function nowSQL(): string {
    return now()->format('Y-m-d H:i:s');
}

/**
 * Get current date string (Y-m-d format)
 */
function todaySQL(): string {
    return now()->format('Y-m-d');
}

/**
 * Parse a datetime string and return DateTime object in IST
 */
function parseDateTime(string $datetime): DateTime {
    return new DateTime($datetime, getAppTimezone());
}

/**
 * Format a datetime for display
 */
function displayDateTime(string $datetime, string $format = 'd M Y, h:i A'): string {
    return parseDateTime($datetime)->format($format);
}

/**
 * Format a date for display
 */
function displayDate(string $date, string $format = 'd M Y'): string {
    return parseDateTime($date)->format($format);
}

/**
 * Format a time for display
 */
function displayTime(string $datetime, string $format = 'h:i A'): string {
    return parseDateTime($datetime)->format($format);
}

/**
 * Get timestamp for MySQL from a DateTime object
 */
function toSQLTimestamp(DateTime $dt): string {
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Create a future timestamp (e.g., for expiry times)
 * 
 * @param int $seconds Number of seconds in the future
 * @return string MySQL formatted timestamp
 */
function futureTimestamp(int $seconds): string {
    $dt = now();
    $dt->modify("+{$seconds} seconds");
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Check if a datetime is in the past
 */
function isPast(string $datetime): bool {
    return parseDateTime($datetime) < now();
}

/**
 * Check if a datetime is in the future
 */
function isFuture(string $datetime): bool {
    return parseDateTime($datetime) > now();
}

/**
 * Get ISO 8601 formatted datetime for JavaScript (with IST offset)
 */
function toISOString(string $datetime): string {
    return parseDateTime($datetime)->format('Y-m-d\TH:i:s' . APP_TIMEZONE_OFFSET);
}
