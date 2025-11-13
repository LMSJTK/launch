-- Migration to add thumbnail support to content table
-- PostgreSQL version

-- Add thumbnail columns
ALTER TABLE global.content ADD COLUMN IF NOT EXISTS thumbnail_filename text;
ALTER TABLE global.content ADD COLUMN IF NOT EXISTS thumbnail_content bytea;

-- Add comment for clarity
COMMENT ON COLUMN global.content.thumbnail_filename IS 'Filename of the thumbnail image for content listing';
COMMENT ON COLUMN global.content.thumbnail_content IS 'Binary content of the thumbnail image';
