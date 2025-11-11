-- Migration to add tags and difficulty fields to content table
-- MySQL Compatible Version

-- Step 1: Add new columns
-- Note: MySQL doesn't support IF NOT EXISTS in ALTER TABLE before 8.0.29
-- These will error if columns already exist, which is safe to ignore
ALTER TABLE content ADD COLUMN tags TEXT;
ALTER TABLE content ADD COLUMN difficulty VARCHAR(50);

-- Step 2: Rename attachment columns (optional - for compatibility with proposed schema)
-- Note: Only run these if you want to match the proposed schema exactly
-- ALTER TABLE content CHANGE COLUMN email_attachment_filename attachment_filename VARCHAR(255);
-- ALTER TABLE content CHANGE COLUMN email_attachment_content attachment_content LONGBLOB;

-- Step 3: Populate tags field from existing content_tags data
UPDATE content
SET tags = (
    SELECT GROUP_CONCAT(tag_name ORDER BY tag_name SEPARATOR ', ')
    FROM content_tags
    WHERE content_tags.content_id = content.id
    GROUP BY content_id
)
WHERE EXISTS (
    SELECT 1 FROM content_tags WHERE content_tags.content_id = content.id
);

-- Step 4: Verify the migration
SELECT
    id,
    title,
    tags,
    (SELECT COUNT(*) FROM content_tags WHERE content_tags.content_id = content.id) as tag_count
FROM content
LIMIT 10;

-- Note: Do NOT drop content_tags table - it contains valuable structured data:
-- - tag_type (interaction, topic, phish-cue)
-- - confidence_score from Claude API
-- - Better for querying and filtering
--
-- The content.tags field is for:
-- - Compatibility with other systems
-- - Quick display in listings
-- - Simple comma-separated output
