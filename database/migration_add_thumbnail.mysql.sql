-- Migration to add thumbnail support to content table
-- MySQL version

-- Add thumbnail columns
-- Note: MySQL doesn't support IF NOT EXISTS for ALTER TABLE ADD COLUMN before 8.0.29
-- These will error if columns already exist, which is safe to ignore
ALTER TABLE content ADD COLUMN thumbnail_filename VARCHAR(255);
ALTER TABLE content ADD COLUMN thumbnail_content LONGBLOB;

-- Add comments for clarity
ALTER TABLE content MODIFY COLUMN thumbnail_filename VARCHAR(255) COMMENT 'Filename of the thumbnail image for content listing';
ALTER TABLE content MODIFY COLUMN thumbnail_content LONGBLOB COMMENT 'Binary content of the thumbnail image';
