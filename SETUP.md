# Setup Guide

## Prerequisites

- PHP 7.4 or higher
- PostgreSQL 12 or higher
- PHP Extensions: `pdo`, `pdo_pgsql`, `zip`, `json`, `curl`
- AWS Account with SNS access
- Claude API key from Anthropic

## Installation Steps

### 1. Database Setup

Create your PostgreSQL database and run the schema:

```bash
# Create database
createdb your_database_name

# Run schema
psql -U your_username -d your_database_name -f database/schema.sql
```

### 2. Configuration

Copy the example configuration and update with your credentials:

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php` and update:

- **Database credentials** (host, port, dbname, username, password)
- **Claude API key** (get from https://console.anthropic.com/)
- **AWS SNS settings** (region, access keys, topic ARN)
- **Base URL** for your installation

### 3. Set Permissions

Ensure the web server can write to the content directory:

```bash
chmod 755 content/
chown www-data:www-data content/  # Adjust user/group as needed
```

### 4. AWS SNS Topic Setup

Create an SNS topic in your AWS account:

```bash
# Using AWS CLI
aws sns create-topic --name content-interactions --region us-east-1
```

Copy the Topic ARN to your `config/config.php`.

### 5. Web Server Configuration

#### Apache

Add this to your virtual host configuration or `.htaccess`:

```apache
<Directory /path/to/launch/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Don't rewrite files or directories
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d

    # Rewrite everything else to index.html
    RewriteRule ^(.*)$ index.html [L]
</IfModule>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/launch/public;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location /content {
        alias /path/to/launch/content;
    }
}
```

#### PHP Built-in Server (Development Only)

For quick testing:

```bash
php -S localhost:8000 -t public
```

Then access: http://localhost:8000

### 6. Test the Installation

1. Open your browser and navigate to your installation URL
2. You should see the Content Management Platform interface
3. Try uploading a simple HTML file to test the system

## Testing

### Upload Test Content

Create a simple HTML file for testing:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Test Content</title>
</head>
<body>
    <h1>Security Awareness Test</h1>

    <p>What is the best way to create a strong password?</p>

    <form>
        <input type="radio" name="answer" value="a"> Use your birthday<br>
        <input type="radio" name="answer" value="b"> Use a long, random phrase<br>
        <input type="radio" name="answer" value="c"> Use the same password everywhere<br>
        <button type="submit">Submit</button>
    </form>

    <script>
        // Simulate SCORM completion
        setTimeout(() => {
            if (typeof RecordTest === 'function') {
                RecordTest(100);
            }
        }, 5000);
    </script>
</body>
</html>
```

Save as `test.html`, zip it, and upload via the interface.

### Generate Launch Link

1. Go to "Generate Launch Link" tab
2. Enter:
   - Recipient ID: `test-user-001`
   - Recipient Email: `test@example.com`
   - Content ID: (copy from uploaded content)
3. Click "Generate Launch Link"
4. Open the link to test content display

### Verify Tracking

Check database tables to verify tracking:

```sql
-- Check tracking links
SELECT * FROM oms_tracking_links;

-- Check interactions
SELECT * FROM content_interactions;

-- Check SNS queue
SELECT * FROM sns_message_queue;
```

## Troubleshooting

### Upload Fails

- Check `content/` directory permissions
- Verify PHP `upload_max_filesize` and `post_max_size` in php.ini
- Check error logs: `tail -f /var/log/apache2/error.log`

### Claude API Errors

- Verify API key in `config/config.php`
- Check Claude API usage limits
- Review PHP error logs

### SNS Publishing Fails

- Verify AWS credentials and permissions
- Ensure SNS topic ARN is correct
- Check AWS IAM permissions for `sns:Publish`

### Database Connection Errors

- Verify PostgreSQL is running
- Check database credentials in config
- Ensure PostgreSQL accepts connections from your host

## Production Deployment

Before deploying to production:

1. Set `'debug' => false` in `config/config.php`
2. Use HTTPS for all connections
3. Implement proper authentication/authorization
4. Set up regular database backups
5. Monitor SNS message queue for failed sends
6. Configure log rotation
7. Set restrictive file permissions on config files

## Security Notes

- Never commit `config/config.php` to version control
- Keep API keys secure
- Implement rate limiting on upload endpoints
- Validate and sanitize all user inputs
- Use prepared statements (already implemented)
- Regularly update dependencies
- Monitor for suspicious upload patterns
