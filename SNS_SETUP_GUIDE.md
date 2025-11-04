# SNS Message Monitoring Guide

This guide explains how to monitor messages being published to your AWS SNS topic.

## Option 1: Quick Database Viewer (Easiest)

The platform stores all SNS messages in the `sns_message_queue` table before sending them. You can view these messages using the built-in monitor:

### Access the Monitor

Navigate to:
```
https://foundational.solutions/ocms/public/sns-monitor.html
```

### Features

- **Real-time monitoring** - Shows all messages sent to SNS
- **Auto-refresh** - Optional 10-second auto-refresh
- **Filter by status** - View sent, pending, or both
- **View message data** - Expand to see full JSON payload
- **Statistics** - Total messages, sent count, pending count

This shows what the platform is attempting to send to SNS, confirming your code is working.

---

## Option 2: SQS Queue Subscription (Production Method)

To actually receive and verify messages published to SNS, set up an SQS queue subscription.

### Step 1: Create an SQS Queue

```bash
# Standard queue (simpler, for testing)
aws sqs create-queue \
  --queue-name content-interactions-receiver \
  --region us-east-1

# OR FIFO queue (matches your FIFO SNS topic)
aws sqs create-queue \
  --queue-name content-interactions-receiver.fifo \
  --attributes FifoQueue=true,ContentBasedDeduplication=true \
  --region us-east-1
```

Save the QueueUrl and QueueArn from the response.

### Step 2: Set Queue Policy

Allow SNS to send messages to the queue:

```bash
aws sqs set-queue-attributes \
  --queue-url https://sqs.us-east-1.amazonaws.com/123456789012/content-interactions-receiver.fifo \
  --attributes file://queue-policy.json
```

**queue-policy.json:**
```json
{
  "Policy": "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Principal\":{\"Service\":\"sns.amazonaws.com\"},\"Action\":\"sqs:SendMessage\",\"Resource\":\"arn:aws:sqs:us-east-1:123456789012:content-interactions-receiver.fifo\",\"Condition\":{\"ArnEquals\":{\"aws:SourceArn\":\"arn:aws:sns:us-east-1:123456789012:content-interactions.fifo\"}}}]}"
}
```

Replace the ARNs with your actual SNS topic ARN and SQS queue ARN.

### Step 3: Subscribe Queue to SNS Topic

```bash
# For standard queue
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:123456789012:content-interactions \
  --protocol sqs \
  --notification-endpoint arn:aws:sqs:us-east-1:123456789012:content-interactions-receiver \
  --region us-east-1

# For FIFO queue with FIFO topic
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:123456789012:content-interactions.fifo \
  --protocol sqs \
  --notification-endpoint arn:aws:sqs:us-east-1:123456789012:content-interactions-receiver.fifo \
  --region us-east-1
```

### Step 4: View Messages in SQS

#### Using AWS Console
1. Go to AWS SQS Console
2. Select your queue
3. Click "Send and receive messages"
4. Click "Poll for messages"
5. Click on a message to view its content

#### Using AWS CLI
```bash
aws sqs receive-message \
  --queue-url https://sqs.us-east-1.amazonaws.com/123456789012/content-interactions-receiver.fifo \
  --max-number-of-messages 10 \
  --region us-east-1
```

#### Using PHP Script

Create a simple viewer at `public/sqs-viewer.php`:

```php
<?php
require_once __DIR__ . '/../api/bootstrap.php';

// Initialize AWS SQS client (reuse SNS config)
$queueUrl = 'https://sqs.us-east-1.amazonaws.com/123456789012/content-interactions-receiver.fifo';

// You would need to implement SQS API calls similar to AWSSNS.php
// For now, use AWS CLI or Console
?>
<!DOCTYPE html>
<html>
<head>
    <title>SQS Message Viewer</title>
</head>
<body>
    <h1>SQS Message Viewer</h1>
    <p>Use AWS CLI to view messages:</p>
    <pre>
aws sqs receive-message \
  --queue-url <?php echo htmlspecialchars($queueUrl); ?> \
  --max-number-of-messages 10
    </pre>
</body>
</html>
```

---

## Option 3: Email Subscription (Simple Testing)

For quick testing, you can subscribe an email address to receive notifications:

```bash
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:123456789012:content-interactions.fifo \
  --protocol email \
  --notification-endpoint your-email@example.com \
  --region us-east-1
```

**Note:** FIFO topics do NOT support email subscriptions. You must use standard topics or SQS/HTTP endpoints.

---

## Option 4: HTTP/HTTPS Endpoint

Create a webhook endpoint that SNS can POST to:

### Step 1: Create Webhook Endpoint

Create `public/sns-webhook.php`:

```php
<?php
/**
 * SNS Webhook Receiver
 * Receives HTTP POST notifications from SNS
 */

// Log all incoming requests
$headers = getallheaders();
$body = file_get_contents('php://input');

// Log to file
$logFile = __DIR__ . '/../logs/sns-webhook.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'headers' => $headers,
    'body' => $body
];

file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

// Parse SNS message
$message = json_decode($body, true);

// Handle subscription confirmation
if (isset($message['Type']) && $message['Type'] === 'SubscriptionConfirmation') {
    // Visit the SubscribeURL to confirm
    $subscribeUrl = $message['SubscribeURL'];
    file_get_contents($subscribeUrl);
    echo "Subscription confirmed";
    exit;
}

// Handle notification
if (isset($message['Type']) && $message['Type'] === 'Notification') {
    // Process the message
    echo "Message received";
    exit;
}

http_response_code(200);
echo "OK";
```

### Step 2: Subscribe Endpoint to SNS

```bash
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:123456789012:content-interactions \
  --protocol https \
  --notification-endpoint https://foundational.solutions/ocms/public/sns-webhook.php \
  --region us-east-1
```

### Step 3: Confirm Subscription

When you subscribe, SNS will send a confirmation request to your endpoint. The webhook will auto-confirm. Check logs:

```bash
tail -f /var/www/html/ocms/logs/sns-webhook.log
```

---

## Message Format

Messages published to SNS have this structure:

```json
{
  "event_type": "content_interaction",
  "tracking_link_id": "e7b861da4659fae44267756f225b4c1e",
  "recipient_id": "test-user-001",
  "content_id": "a1b2c3d4e5f6...",
  "timestamp": "2025-11-04T14:30:00Z",
  "interactions": [
    {
      "tag": "password-security",
      "type": "click",
      "value": "answer_b",
      "success": true,
      "timestamp": "2025-11-04T14:29:45Z"
    }
  ],
  "final_score": 100,
  "status": "passed",
  "completed_at": "2025-11-04T14:30:00Z"
}
```

---

## Troubleshooting

### Messages not appearing in SQS

1. Check SNS subscription status:
   ```bash
   aws sns list-subscriptions-by-topic \
     --topic-arn arn:aws:sns:us-east-1:123456789012:content-interactions.fifo
   ```

2. Verify queue policy allows SNS to send messages

3. Check SNS topic publish permissions in IAM

### Messages in database but not SNS

1. Check AWS credentials in `config/config.php`
2. Verify SNS topic ARN is correct
3. Check PHP error logs for SNS publish errors
4. Verify IAM user has `sns:Publish` permission

### FIFO Topic Issues

- FIFO topics require `.fifo` suffix in ARN
- FIFO topics can only subscribe to FIFO queues
- FIFO topics do NOT support email or SMS subscriptions
- MessageGroupId is required (automatically set to recipient_id)

---

## Recommended Setup

For production monitoring:

1. **Use the database viewer** (`sns-monitor.html`) for quick checks
2. **Set up an SQS FIFO queue** for reliable message capture
3. **Process SQS messages** with a background worker for analytics
4. **Set up CloudWatch alarms** for SNS publish failures

This gives you visibility at every stage of the message pipeline!
