-- Complete database setup for KESA Learning Certificate System
-- Run this in your Hostinger database (don't include CREATE DATABASE line)

-- Create certificates table with correct structure
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

-- Create admin_users table
DROP TABLE IF EXISTS admin_users;
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user (username: admin, password: admin123)
INSERT INTO admin_users (username, password) VALUES 
('admin', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm');

-- Insert sample certificate for testing
INSERT INTO certificates (certificate_number, student_name, course_name, course_url, issue_date) VALUES 
('SAMPLE001', 'John Doe', 'Sample Course', 'https://kesalearn.com/courses/sample', '2024-01-15');
