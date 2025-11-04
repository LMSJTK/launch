# Headless PHP Content Platform

A vanilla PHP platform for managing SCORM/HTML content with Claude AI-powered tagging, interaction tracking, and AWS SNS integration.

## Features

- **Content Upload**: Support for SCORM zips, HTML zips, raw HTML, and video files (mp4)
- **AI-Powered Tagging**: Automatic content tagging using Claude API
- **NIST Phish Scales**: Auto-cue detection for phishing training emails
- **Content Launch**: Generate unique launch links for recipients
- **Interaction Tracking**: Track user interactions with content elements
- **Score Recording**: SCORM-compatible score tracking
- **AWS SNS Integration**: Publish interaction events to SNS topics

## Directory Structure

```
├── api/                    # API endpoints
│   ├── upload.php         # Content upload endpoint
│   ├── launch-link.php    # Generate launch links
│   ├── record-score.php   # Record test scores
│   └── track-interaction.php # Track interactions
├── config/                 # Configuration files
│   ├── config.example.php # Example configuration
│   └── config.php         # Actual config (not in git)
├── content/               # Uploaded content storage
├── database/              # Database schemas
│   └── schema.sql        # PostgreSQL schema
├── lib/                   # Core libraries
│   ├── Database.php      # PDO database connection
│   ├── ClaudeAPI.php     # Claude API integration
│   ├── AWSSNS.php        # AWS SNS integration
│   ├── ContentProcessor.php # Post-upload processing
│   └── TrackingManager.php  # Interaction tracking
├── public/                # Web interface
│   ├── index.html        # Upload/management interface
│   ├── launch.php        # Content player
│   └── assets/           # CSS, JS assets
└── includes/              # Shared includes
    └── tracking.js       # Client-side tracking script
```

## Setup

1. **Database Setup**:
   ```bash
   psql -U your_username -d your_database -f database/schema.sql
   ```

2. **Configuration**:
   ```bash
   cp config/config.example.php config/config.php
   # Edit config/config.php with your credentials
   ```

3. **Permissions**:
   ```bash
   chmod 755 content/
   chmod 644 config/config.php
   ```

4. **Web Server**: Configure your web server to point to the `/public` directory or use PHP's built-in server:
   ```bash
   php -S localhost:8000 -t public
   ```

## API Endpoints

### Upload Content
```
POST /api/upload.php
```

### Request Launch Link
```
POST /api/launch-link.php
Body: {
  "recipient_id": "recipient-123",
  "content_id": "content-456"
}
```

### Record Score
```
POST /api/record-score.php
Body: {
  "tracking_link_id": "link-789",
  "score": 100
}
```

### Track Interaction
```
POST /api/track-interaction.php
Body: {
  "tracking_link_id": "link-789",
  "tag_name": "ransomware",
  "interaction_type": "click",
  "success": true
}
```

## Requirements

- PHP 7.4+
- PostgreSQL 12+
- PHP Extensions: pdo, pdo_pgsql, zip, json, curl
- AWS Account (for SNS)
- Claude API Key (Anthropic)

## License

Proprietary
