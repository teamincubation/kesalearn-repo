-- Add password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
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

-- Add email field to admin_users table if it doesn't exist
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL;

-- Update admin user with email
UPDATE admin_users SET email = 'admin@kesalearn.com' WHERE username = 'admin';
