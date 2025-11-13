<?php
/**
 * Configuration file for Headless PHP Content Platform
 * Copy this file to config.php and fill in your actual credentials
 */

return [
    // Database Configuration
    'database' => [
        'type' => 'pgsql', // 'pgsql' for PostgreSQL or 'mysql' for MySQL
        'host' => 'localhost',
        'port' => '5432', // 5432 for PostgreSQL, 3306 for MySQL
        'dbname' => 'your_database_name',
        'username' => 'your_db_username',
        'password' => 'your_db_password',
        'schema' => 'global' // PostgreSQL schema (ignored for MySQL)
    ],

    // Claude API Configuration
    'claude' => [
        'api_key' => 'your_claude_api_key_here',
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 8192, // Increased to handle large educational content without truncation
        'max_content_size' => 500000 // Skip AI processing if content exceeds this size (in bytes)
    ],

    // AWS SNS Configuration
    'aws_sns' => [
        'region' => 'us-east-1',
        'access_key_id' => 'your_aws_access_key_id',
        'secret_access_key' => 'your_aws_secret_access_key',
        'topic_arn' => 'arn:aws:sns:us-east-1:123456789012:content-interactions'
    ],

    // Content Storage
    'content' => [
        'upload_dir' => __DIR__ . '/../content/',
        'max_upload_size' => 100 * 1024 * 1024, // 100MB
        'allowed_types' => [
            'scorm' => ['zip'],
            'html' => ['zip'],
            'video' => ['mp4', 'webm', 'ogg'],
            'raw_html' => ['html']
        ]
    ],

    // Application Settings
    'app' => [
        'base_url' => 'http://localhost',
        'debug' => true,
        'timezone' => 'UTC'
    ],

    // API Authentication
    'api' => [
        'bearer_token' => 'your_secure_random_token_here', // Generate a secure random token
        'enabled' => true // Set to false to disable authentication (not recommended for production)
    ],

    // SCORM Settings
    'scorm' => [
        'passing_score' => 80
    ]
];
