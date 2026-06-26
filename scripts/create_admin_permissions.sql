-- Admin Permissions System for KESA Learn
-- This creates a flexible permission system for admins

-- Create admin_permissions table
CREATE TABLE IF NOT EXISTS admin_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    section VARCHAR(50) NOT NULL,
    can_access TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_section (user_id, section),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Available sections:
-- dashboard, analytics, events, registrations, users, instructors, live_sessions,
-- announcements, certificates, banners, content, feedbacks, ratings, 
-- maintenance, tools, settings, logs, admin_management

-- Insert default permissions for super admin (admin@kesalearn.com)
-- This will be handled in PHP code
