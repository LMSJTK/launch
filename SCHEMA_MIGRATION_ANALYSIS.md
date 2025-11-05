# Schema Migration Analysis

## Current Schema vs Proposed Schema

### Content Table Changes

**Current:**
```sql
CREATE TABLE global.content (
    id text,
    company_id text,
    title text,
    description text,
    content_type text,
    content_preview text,
    content_url text,
    email_from_address text,
    email_subject text,
    email_body_html text,
    email_attachment_filename text,    -- Different
    email_attachment_content bytea,    -- Different
    created_at timestamp,
    updated_at timestamp
);

-- Plus separate table:
CREATE TABLE global.content_tags (
    id SERIAL PRIMARY KEY,
    content_id text,
    tag_name text,
    tag_type text,
    confidence_score numeric(3,2)
);
```

**Proposed:**
```sql
CREATE TABLE global.content (
    id text,
    company_id text,
    title text,
    description text,
    tags text,                  -- NEW: single text field (was separate table)
    difficulty text,            -- NEW
    content_type text,
    content_preview text,
    content_url text,
    email_from_address text,
    email_subject text,
    email_body_html text,
    attachment_filename text,   -- RENAMED (was email_attachment_filename)
    attachment_content bytea,   -- RENAMED (was email_attachment_content)
    created_at timestamp,
    updated_at timestamp
);
```

### Tracking Changes

**Current:**
```sql
-- Simple launch tracking
CREATE TABLE global.oms_tracking_links (
    id text PRIMARY KEY,
    recipient_id text,
    content_id text,
    launch_url text,
    status text,
    score integer,
    viewed_at timestamp,
    completed_at timestamp,
    created_at timestamp,
    updated_at timestamp
);

-- Detailed interaction tracking
CREATE TABLE global.content_interactions (
    id SERIAL PRIMARY KEY,
    tracking_link_id text,
    tag_name text,
    interaction_type text,
    interaction_value text,
    success boolean,
    interaction_data jsonb,
    created_at timestamp
);

-- Recipient skill tracking
CREATE TABLE global.recipient_tag_scores (
    id SERIAL PRIMARY KEY,
    recipient_id text,
    tag_name text,
    score_count integer,
    total_attempts integer,
    last_updated timestamp
);
```

**Proposed:**
```sql
-- Campaign-based tracking (much more complex)
CREATE TABLE global.training_tracking (
    id text PRIMARY KEY,
    training_id text,
    recipient_id text,
    unique_tracking_id text,
    status text,
    training_reported_at timestamp,
    training_sent_at timestamp,
    training_opened_at timestamp,
    training_clicked_at timestamp,
    training_completed_at timestamp,
    follow_on_reported_at timestamp,
    follow_on_sent_at timestamp,
    follow_on_opened_at timestamp,
    follow_on_clicked_at timestamp,
    follow_on_completed_at timestamp,
    last_action_at timestamp,
    created_at timestamp,
    updated_at timestamp
);

-- Plus new campaign structure
CREATE TABLE global.campaign (...);
CREATE TABLE global.training (...);
```

---

## 🔴 INFORMATION LOST

If you migrate to the proposed schema, you will LOSE:

### 1. **Detailed Tag Information** ❌
**Current:** Structured tags in `content_tags` table
```sql
content_id | tag_name           | tag_type     | confidence_score
-----------|-------------------|--------------|------------------
abc123     | password-security | interaction  | 1.00
abc123     | ransomware        | interaction  | 0.95
```

**Proposed:** Single text field
```sql
tags: "password-security, ransomware, phishing"
```

**Lost:**
- Tag type (interaction, topic, phish-cue)
- Confidence scores from Claude API
- Ability to query by specific tag efficiently
- Ability to join/aggregate by tag

### 2. **Detailed Interaction Tracking** ❌
**Current:** `content_interactions` table tracks EVERY tagged element interaction
```sql
tracking_link_id | tag_name          | interaction_type | value    | success
-----------------|-------------------|------------------|----------|--------
xyz789           | password-security | click           | answer_b | true
xyz789           | ransomware        | input           | correct  | true
```

**Proposed:** Only high-level timestamps (opened, clicked, completed)

**Lost:**
- Which specific tagged elements were interacted with
- What values were entered
- Success/failure per interaction
- Detailed interaction timeline
- Data for analytics on specific question performance

### 3. **Recipient Skill Scores** ❌
**Current:** `recipient_tag_scores` tracks cumulative skill levels
```sql
recipient_id | tag_name          | score_count | total_attempts
-------------|-------------------|-------------|---------------
user-001     | password-security | 5           | 7
user-001     | ransomware        | 3           | 4
```

**Proposed:** No equivalent

**Lost:**
- Individual skill tracking per topic
- Pass rates per topic per user
- Training effectiveness metrics
- Ability to identify skill gaps

### 4. **SNS Message Queue** ❌
**Current:** `sns_message_queue` table stores messages before SNS publish
```sql
tracking_link_id | message_data                    | sent  | sent_at
-----------------|---------------------------------|-------|--------
xyz789           | {"interactions":[...],"score":100} | true  | 2025-11-04
```

**Proposed:** No equivalent

**Lost:**
- Message retry capability
- Audit trail of what was sent to SNS
- Ability to republish failed messages
- SNS monitoring dashboard

### 5. **Direct Content Launch Links** ❌
**Current:** Simple direct launch via `oms_tracking_links`
```
https://example.com/ocms/public/launch.php?tid=xyz789
```

**Proposed:** Requires training/campaign structure
- Must create a campaign
- Must create a training
- Must create training_tracking record

**Lost:**
- Ability to launch individual content directly
- Simple ad-hoc content sharing
- Quick testing/preview of content

---

## 🟢 INFORMATION GAINED

What you would GET with the proposed schema:

### 1. **Campaign Management** ✅
```sql
CREATE TABLE global.campaign (
    id text PRIMARY KEY,
    name text,
    email_content_id text,
    attachment_content_id text,
    landing_content_id text,
    training_content_id text,
    follow_on_content_id text
);
```

**Gained:**
- Reusable campaign templates
- Multiple content types per campaign (email + attachment + landing page + training)
- Follow-on content support

### 2. **Scheduled Training** ✅
```sql
CREATE TABLE global.training (
    id text PRIMARY KEY,
    scheduled_at timestamp,
    ends_at timestamp,
    status text,
    draft_state text
);
```

**Gained:**
- Schedule trainings for future delivery
- Training end dates
- Draft vs published states
- Campaign-based training organization

### 3. **Multi-Step Tracking** ✅
```sql
training_sent_at timestamp,
training_opened_at timestamp,
training_clicked_at timestamp,
training_completed_at timestamp,
follow_on_sent_at timestamp,
follow_on_opened_at timestamp,
follow_on_clicked_at timestamp,
follow_on_completed_at timestamp
```

**Gained:**
- Detailed email funnel metrics (sent → opened → clicked → completed)
- Follow-on content tracking
- Standard phishing simulation metrics

### 4. **Recipient Groups** ✅
```sql
training.recipient_group_id text
```

**Gained:**
- Group-based training assignment
- Bulk recipient management

---

## 🔄 Migration Path

### Option 1: Full Migration (Lose Detail, Gain Structure)

**Pros:**
- Simpler schema
- Campaign management
- Standard phishing training workflow

**Cons:**
- Lose all detailed interaction data
- Lose skill tracking
- Lose SNS integration
- Lose direct content launches

### Option 2: Hybrid Approach (Keep Both)

Keep your current detailed tracking AND add the campaign tables:

```sql
-- Keep current tables:
- content (with modifications)
- content_tags
- content_interactions
- recipient_tag_scores
- sns_message_queue

-- Add new tables:
+ campaign
+ training
+ training_tracking
```

**Mapping:**
```sql
-- oms_tracking_links → training_tracking
INSERT INTO training_tracking (
    training_id,
    recipient_id,
    unique_tracking_id,
    training_completed_at
)
SELECT
    'migration',
    recipient_id,
    id,
    completed_at
FROM oms_tracking_links;

-- content_tags → content.tags
UPDATE content
SET tags = (
    SELECT string_agg(tag_name, ', ')
    FROM content_tags
    WHERE content_tags.content_id = content.id
);
```

### Option 3: Extend Current Schema (Recommended)

Add campaign functionality WITHOUT losing current detail:

```sql
-- Add to content table:
ALTER TABLE content ADD COLUMN tags text;
ALTER TABLE content ADD COLUMN difficulty text;
ALTER TABLE content RENAME COLUMN email_attachment_filename TO attachment_filename;
ALTER TABLE content RENAME COLUMN email_attachment_content TO attachment_content;

-- Add new tables:
CREATE TABLE campaign (...);
CREATE TABLE training (...);

-- Keep existing tables:
- content_tags (populate content.tags from this for backwards compat)
- content_interactions
- recipient_tag_scores
- sns_message_queue
```

---

## 📋 Summary

**Critical Losses:**
1. ❌ Detailed per-element interaction tracking
2. ❌ Recipient skill scores by topic
3. ❌ SNS message queue and monitoring
4. ❌ Tag metadata (type, confidence)
5. ❌ Simple direct content launches

**Significant Gains:**
1. ✅ Campaign management
2. ✅ Scheduled training sessions
3. ✅ Email funnel metrics (sent/opened/clicked/completed)
4. ✅ Follow-on content support
5. ✅ Recipient group management

**Recommendation:**
Use **Option 3 (Extend)** - Keep your detailed tracking and add campaign features on top. This gives you the best of both worlds.

Would you like me to create migration scripts for any of these options?
