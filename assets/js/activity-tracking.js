/**
 * KESA Learn - Global Activity Tracking
 * Tracks all user clicks, button clicks, link clicks, and page interactions
 * Sends activity data to server for logging and updating last_activity timestamp
 */

(function() {
    'use strict';
    
    // Track all clicks on the page
    document.addEventListener('click', function(e) {
        const target = e.target.closest('a, button, [role="button"], input[type="button"], input[type="submit"], .clickable, [data-track]');
        
        if (target) {
            trackActivity(target, 'click');
        }
    }, true);
    
    // Track form submissions
    document.addEventListener('submit', function(e) {
        if (e.target) {
            trackActivity(e.target, 'form_submit');
        }
    }, true);
    
    // Track page visibility changes
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            trackActivity(document, 'page_focused');
        }
    });
    
    // Track user interactions on input fields
    document.addEventListener('change', function(e) {
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA')) {
            trackActivity(e.target, 'form_change');
        }
    }, true);
    
    /**
     * Track an activity and send to server
     */
    function trackActivity(element, activityType) {
        if (!element) return;
        
        const elementType = element.tagName?.toLowerCase() || 'unknown';
        const elementId = element.id || '';
        let elementText = '';
        let pageName = getPageName();
        
        // Get element text safely
        if (element.tagName === 'BUTTON' || element.tagName === 'A') {
            elementText = element.innerText?.substring(0, 100) || element.textContent?.substring(0, 100) || '';
        } else if (element.tagName === 'INPUT') {
            elementText = element.name || element.placeholder || '';
        } else {
            elementText = element.title || element.alt || '';
        }
        
        // Map common page clicks to specific page names
        let pageClickInfo = '';
        if (elementText) {
            pageClickInfo = elementText.toLowerCase();
            // Map specific clicks
            if (pageClickInfo.includes('profile')) pageClickInfo = 'Clicked on Profile';
            else if (pageClickInfo.includes('certificate')) pageClickInfo = 'Clicked on Certificates';
            else if (pageClickInfo.includes('download')) pageClickInfo = 'Download Certificates';
            else if (pageClickInfo.includes('feedback')) pageClickInfo = 'Clicked on Feedback';
            else if (pageClickInfo.includes('submit')) pageClickInfo = 'Submit Feedback';
            else if (pageClickInfo.includes('event')) pageClickInfo = 'Clicked on Events';
            else if (pageClickInfo.includes('verify')) pageClickInfo = 'Verify Certificate';
            else if (pageClickInfo.includes('attend')) pageClickInfo = 'Attend Live Session';
            else if (pageClickInfo.includes('recorded')) pageClickInfo = 'Watch Recorded Session';
            else if (pageClickInfo.includes('logout')) pageClickInfo = 'Logout';
            else pageClickInfo = 'Clicked: ' + elementText;
        }
        
        // Prepare data to send
        const data = {
            activity_type: activityType,
            element_type: elementType,
            element_id: elementId,
            element_text: pageClickInfo || elementText,
            page_url: pageName || (window.location.pathname + window.location.search),
            timestamp: new Date().toISOString()
        };
        
        // Send activity to server asynchronously (non-blocking)
        sendActivityToServer(data);
    }
    
    /**
     * Get human-readable page name from URL
     */
    function getPageName() {
        const path = window.location.pathname.toLowerCase();
        
        if (path.includes('/profile')) return 'Profile Page';
        if (path.includes('/certificate') || path.includes('/certificates')) return 'Certificates Page';
        if (path.includes('/feedback')) return 'Feedback Page';
        if (path.includes('/event') || path.includes('/events')) return 'Events Page';
        if (path.includes('/live')) return 'Live Sessions Page';
        if (path.includes('/recorded')) return 'Recorded Sessions Page';
        if (path.includes('/verify')) return 'Certificate Verification Page';
        if (path.includes('/dashboard')) return 'Dashboard';
        if (path.includes('/course')) return 'Course Page';
        if (path.includes('/lesson')) return 'Lesson Page';
        
        return path;
    }
    
    /**
     * Send activity data to server endpoint
     */
    function sendActivityToServer(data) {
        // Use JSON fetch for most requests
        fetch('/api/track-activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            keepalive: true
        }).catch(err => {
            // Silently fail - don't interrupt user experience
            console.log('[KESA] Activity tracking failed:', err.message);
        });
        
        // Also use sendBeacon for page unload
        if (navigator.sendBeacon) {
            const beacon = new FormData();
            beacon.append('data', JSON.stringify(data));
            navigator.sendBeacon('/api/track-activity.php', beacon);
        }
    }
    
    // Track initial page load
    window.addEventListener('load', function() {
        trackActivity(window, 'page_load');
    });
    
    // Optionally track before unload
    window.addEventListener('beforeunload', function() {
        // Send any pending activities
        if (navigator.sendBeacon) {
            const data = new FormData();
            data.append('data', JSON.stringify({
                activity_type: 'page_unload',
                timestamp: new Date().toISOString()
            }));
            navigator.sendBeacon('/api/track-activity.php', data);
        }
    });
    
})();
