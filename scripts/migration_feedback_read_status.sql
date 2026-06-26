-- KESA Learn - Feedback System Update
-- Adds is_read column to feedbacks table for draft/read status management
-- This allows marking feedbacks as read without approving them for publication

-- Add is_read column if it doesn't exist
ALTER TABLE `feedbacks` ADD COLUMN IF NOT EXISTS `is_read` TINYINT(1) DEFAULT 0;

-- Update indexes for better filtering performance
ALTER TABLE `feedbacks` ADD KEY IF NOT EXISTS `idx_is_read` (`is_read`);
ALTER TABLE `feedbacks` ADD KEY IF NOT EXISTS `idx_approval_status` (`is_approved`, `is_read`);

-- Ensure created_at has a timestamp
ALTER TABLE `feedbacks` MODIFY `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
