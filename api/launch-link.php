<?php
/**
 * Launch Link API
 * Creates a tracking link for content launch
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['recipient_id', 'content_id']);

    $recipientId = $input['recipient_id'];
    $contentId = $input['content_id'];

    // Verify recipient exists (or create if needed)
    $recipient = $db->fetchOne(
        'SELECT * FROM recipients WHERE id = :id',
        [':id' => $recipientId]
    );

    if (!$recipient) {
        // Auto-create recipient if doesn't exist
        $db->insert('recipients', [
            'id' => $recipientId,
            'company_id' => $input['company_id'] ?? 'default',
            'email' => $input['email'] ?? '',
            'first_name' => $input['first_name'] ?? '',
            'last_name' => $input['last_name'] ?? ''
        ]);
    }

    // Verify content exists
    $content = $db->fetchOne(
        'SELECT * FROM content WHERE id = :id',
        [':id' => $contentId]
    );

    if (!$content) {
        sendJSON(['error' => 'Content not found'], 404);
    }

    // Create tracking link
    $result = $trackingManager->createTrackingLink($recipientId, $contentId);

    sendJSON([
        'success' => true,
        'tracking_link_id' => $result['tracking_link_id'],
        'launch_url' => $config['app']['base_url'] . $result['launch_url'],
        'content' => [
            'id' => $content['id'],
            'title' => $content['title'],
            'type' => $content['content_type']
        ]
    ]);

} catch (Exception $e) {
    error_log("Launch Link Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to create launch link',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
