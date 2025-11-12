<?php
/**
 * Content Upload API
 * Handles file uploads for SCORM, HTML, videos, and raw HTML
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_log("=== UPLOAD.PHP STARTING ===");

require_once __DIR__ . '/bootstrap.php';
error_log("Bootstrap loaded successfully");

// Validate bearer token authentication
validateBearerToken($config);

/**
 * Generate a preview link for content
 */
function generatePreviewLink($contentId, $db, $trackingManager, $config) {
    // Ensure preview recipient exists
    $previewRecipient = $db->fetchOne(
        'SELECT * FROM recipients WHERE id = :id',
        [':id' => 'preview']
    );

    if (!$previewRecipient) {
        $db->insert('recipients', [
            'id' => 'preview',
            'company_id' => 'system',
            'email' => 'preview@system.local',
            'first_name' => 'Preview',
            'last_name' => 'User'
        ]);
    }

    // Create tracking link for preview
    $result = $trackingManager->createTrackingLink('preview', $contentId);
    $previewUrl = $config['app']['base_url'] . $result['launch_url'];

    // Update content with preview link
    $db->update('content',
        ['content_preview' => $previewUrl],
        'id = :id',
        [':id' => $contentId]
    );

    return $previewUrl;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Wrong method: " . $_SERVER['REQUEST_METHOD']);
    sendJSON(['error' => 'Method not allowed'], 405);
}

error_log("POST request received");

try {
    // Get form data
    $contentType = $_POST['content_type'] ?? null;
    $title = $_POST['title'] ?? 'Untitled Content';
    $description = $_POST['description'] ?? '';
    $companyId = $_POST['company_id'] ?? 'default';

    error_log("Content type: " . ($contentType ?? 'NULL'));
    error_log("Title: " . $title);

    if (!$contentType) {
        sendJSON(['error' => 'content_type is required'], 400);
    }

    // Generate unique content ID
    $contentId = bin2hex(random_bytes(16));

    // Handle different content types
    switch ($contentType) {
        case 'scorm':
        case 'html':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendJSON(['error' => 'File upload failed'], 400);
            }

            $file = $_FILES['file'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($fileExt !== 'zip') {
                sendJSON(['error' => 'Only ZIP files are allowed for ' . $contentType], 400);
            }

            // Move uploaded file to temp location
            $tempPath = $config['content']['upload_dir'] . 'temp_' . $contentId . '.zip';
            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                sendJSON(['error' => 'Failed to save uploaded file'], 500);
            }

            // Insert content record
            $db->insert('content', [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => $contentType,
                'content_url' => null // Will be set after processing
            ]);

            // Process content
            $result = $contentProcessor->processContent($contentId, $contentType, $tempPath);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Content uploaded and processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ]);
            break;

        case 'video':
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendJSON(['error' => 'File upload failed'], 400);
            }

            $file = $_FILES['file'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($fileExt, ['mp4', 'webm', 'ogg'])) {
                sendJSON(['error' => 'Invalid video format. Allowed: mp4, webm, ogg'], 400);
            }

            // Create directory
            $videoDir = $config['content']['upload_dir'] . $contentId . '/';
            if (!is_dir($videoDir)) {
                mkdir($videoDir, 0755, true);
            }

            $videoPath = $videoDir . 'video.' . $fileExt;
            if (!move_uploaded_file($file['tmp_name'], $videoPath)) {
                sendJSON(['error' => 'Failed to save video file'], 500);
            }

            // Insert content record
            $db->insert('content', [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'video',
                'content_url' => $contentId . '/video.' . $fileExt
            ]);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Video uploaded successfully',
                'path' => $contentId . '/video.' . $fileExt,
                'preview_url' => $previewUrl
            ]);
            break;

        case 'raw_html':
            $htmlContent = $_POST['html_content'] ?? null;
            if (!$htmlContent) {
                sendJSON(['error' => 'html_content is required'], 400);
            }

            // Insert content record
            $db->insert('content', [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'raw_html',
                'content_url' => null
            ]);

            // Process content
            $result = $contentProcessor->processContent($contentId, 'raw_html', $htmlContent);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'HTML content processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ]);
            break;

        case 'landing':
            $htmlContent = $_POST['html_content'] ?? null;
            if (!$htmlContent) {
                sendJSON(['error' => 'html_content is required'], 400);
            }

            // Insert content record
            $db->insert('content', [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'landing',
                'content_url' => null
            ]);

            // Process landing page content (similar to raw_html but designated as landing)
            $result = $contentProcessor->processContent($contentId, 'landing', $htmlContent);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            sendJSON([
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Landing page processed successfully',
                'tags' => $result['tags'] ?? [],
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ]);
            break;

        case 'email':
            $emailHTML = $_POST['email_html'] ?? null;
            $emailSubject = $_POST['email_subject'] ?? '';
            $emailFrom = $_POST['email_from'] ?? '';

            if (!$emailHTML) {
                sendJSON(['error' => 'email_html is required'], 400);
            }

            // Handle attachment if provided
            $attachmentFilename = null;
            $attachmentContent = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $attachmentFile = $_FILES['attachment'];
                $attachmentFilename = basename($attachmentFile['name']);

                // Create content directory
                $contentDir = $config['content']['upload_dir'] . $contentId . '/';
                if (!is_dir($contentDir)) {
                    mkdir($contentDir, 0755, true);
                }

                // Save attachment to content directory
                $attachmentPath = $contentDir . $attachmentFilename;
                if (!move_uploaded_file($attachmentFile['tmp_name'], $attachmentPath)) {
                    sendJSON(['error' => 'Failed to save attachment file'], 500);
                }

                // Read file content as binary for database storage
                $attachmentContent = file_get_contents($attachmentPath);
            }

            // Save email HTML to temp file
            $tempPath = $config['content']['upload_dir'] . 'temp_' . $contentId . '.html';
            file_put_contents($tempPath, $emailHTML);

            // Insert content record
            $insertData = [
                'id' => $contentId,
                'company_id' => $companyId,
                'title' => $title,
                'description' => $description,
                'content_type' => 'email',
                'email_subject' => $emailSubject,
                'email_from_address' => $emailFrom,
                'email_body_html' => $emailHTML,
                'content_url' => null
            ];

            // Add attachment fields if attachment was provided
            if ($attachmentFilename !== null) {
                $insertData['email_attachment_filename'] = $attachmentFilename;
                $insertData['email_attachment_content'] = $attachmentContent;
            }

            $db->insert('content', $insertData);

            // Process email content
            $result = $contentProcessor->processContent($contentId, 'email', $tempPath);

            // Clean up temp file
            unlink($tempPath);

            // Generate preview link
            $previewUrl = generatePreviewLink($contentId, $db, $trackingManager, $config);

            $response = [
                'success' => true,
                'content_id' => $contentId,
                'message' => 'Email content processed successfully',
                'cues' => $result['cues'] ?? [],
                'difficulty' => $result['difficulty'] ?? null,
                'path' => $result['path'],
                'preview_url' => $previewUrl
            ];

            // Include attachment info if present
            if ($attachmentFilename !== null) {
                $response['attachment_filename'] = $attachmentFilename;
                $response['attachment_size'] = strlen($attachmentContent);
            }

            sendJSON($response);
            break;

        default:
            sendJSON(['error' => 'Invalid content_type'], 400);
    }

} catch (Exception $e) {
    error_log("Upload Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendJSON([
        'error' => 'Upload failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $config['app']['debug'] ? $e->getTraceAsString() : null
    ], 500);
}
