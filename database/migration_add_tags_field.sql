-- Migration to add tags and difficulty fields to content table
-- Compatible with proposed schema while maintaining existing functionality

-- Step 1: Add new columns
ALTER TABLE global.content ADD COLUMN IF NOT EXISTS tags text;
ALTER TABLE global.content ADD COLUMN IF NOT EXISTS difficulty text;

-- Step 2: Rename attachment columns (optional - for compatibility with proposed schema)
-- Note: Only run these if you want to match the proposed schema exactly
-- ALTER TABLE global.content RENAME COLUMN email_attachment_filename TO attachment_filename;
-- ALTER TABLE global.content RENAME COLUMN email_attachment_content TO attachment_content;

-- Step 3: Populate tags field from existing content_tags data
UPDATE global.content
SET tags = (
    SELECT string_agg(tag_name, ', ' ORDER BY tag_name)
    FROM global.content_tags
    WHERE content_tags.content_id = content.id
    GROUP BY content_id
)
WHERE EXISTS (
    SELECT 1 FROM global.content_tags WHERE content_tags.content_id = content.id
);

-- Step 4: Verify the migration
SELECT
    id,
    title,
    tags,
    (SELECT COUNT(*) FROM global.content_tags WHERE content_tags.content_id = content.id) as tag_count
FROM global.content
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
