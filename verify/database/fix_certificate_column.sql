-- Check if the certificate_image column exists and fix if needed
DESCRIBE certificates;

-- If the column doesn't exist or is named differently, add it
ALTER TABLE certificates ADD COLUMN IF NOT EXISTS certificate_image VARCHAR(255);

-- If you have an old column named certificate_pdf, rename it
-- ALTER TABLE certificates CHANGE certificate_pdf certificate_image VARCHAR(255);

-- Update any existing records that might have the wrong column
-- UPDATE certificates SET certificate_image = certificate_pdf WHERE certificate_image IS NULL AND certificate_pdf IS NOT NULL;
