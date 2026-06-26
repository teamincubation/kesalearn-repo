-- KESA Learn - Insert Admin User
-- Run this SQL in phpMyAdmin to create admin user
-- 
-- Default credentials:
--   Email: admin@kesalearn.com
--   Password: KesaAdmin@2024
--
-- IMPORTANT: Change the password after first login!

INSERT INTO `users` (
    `name`,
    `email`,
    `password_hash`,
    `phone`,
    `role`,
    `email_verified`,
    `created_at`,
    `updated_at`
) VALUES (
    'KESA Admin',
    'admin@kesalearn.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    NULL,
    'admin',
    1,
    NOW(),
    NOW()
);

-- The password hash above is for: KesaAdmin@2024
-- Generated using: password_hash('KesaAdmin@2024', PASSWORD_DEFAULT)

-- If you want to use a different password, generate a new hash using PHP:
-- <?php echo password_hash('YourNewPassword', PASSWORD_DEFAULT); ?>
