<?php
/**
 * Track View API
 * Records when content is viewed
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['tracking_link_id']);

    $trackingLinkId = $input['tracking_link_id'];

    $result = $trackingManager->trackView($trackingLinkId);

    sendJSON([
        'success' => true,
        'message' => 'View tracked successfully'
    ]);

} catch (Exception $e) {
    error_log("Track View Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to track view',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
