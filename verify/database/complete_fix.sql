-- Complete database fix for certificate system

-- Ensure the certificates table has the correct structure
DROP TABLE IF EXISTS certificates;
CREATE TABLE certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_number VARCHAR(50) UNIQUE NOT NULL,
    student_name VARCHAR(255) NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    course_banner VARCHAR(255) DEFAULT NULL,
    course_url VARCHAR(500) DEFAULT NULL,
    certificate_image VARCHAR(255) DEFAULT NULL,
    issue_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_certificate_number (certificate_number),
    INDEX idx_student_name (student_name)
);

-- Recreate admin_users table
DROP TABLE IF EXISTS admin_users;
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user (username: admin, password: admin123)
INSERT INTO admin_users (username, password, email) VALUES 
('admin', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'admin@kesalearn.com');

-- Create password reset tokens table
DROP TABLE IF EXISTS password_reset_tokens;
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
);

-- Insert test certificate with proper structure
INSERT INTO certificates (certificate_number, student_name, course_name, course_url, issue_date) VALUES 
('TEST001', 'Test Student', 'Test Course', 'https://kesalearn.com/courses/test', '2024-01-15');

-- Show final structure
DESCRIBE certificates;
SELECT * FROM certificates;
