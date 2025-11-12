<?php
/**
 * Track Interaction API
 * Records interactions with tagged elements
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['tracking_link_id', 'tag_name', 'interaction_type']);

    $trackingLinkId = $input['tracking_link_id'];
    $tagName = $input['tag_name'];
    $interactionType = $input['interaction_type'];
    $interactionValue = $input['interaction_value'] ?? null;
    $success = $input['success'] ?? null;

    $result = $trackingManager->trackInteraction(
        $trackingLinkId,
        $tagName,
        $interactionType,
        $interactionValue,
        $success
    );

    sendJSON([
        'success' => true,
        'message' => 'Interaction tracked successfully'
    ]);

} catch (Exception $e) {
    error_log("Track Interaction Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to track interaction',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
