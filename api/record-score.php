<?php
/**
 * Record Score API
 * Records test score and publishes to SNS
 */

require_once __DIR__ . '/bootstrap.php';

// Validate bearer token authentication
validateBearerToken($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['error' => 'Method not allowed'], 405);
}

try {
    $input = getJSONInput();
    validateRequired($input, ['tracking_link_id', 'score']);

    $trackingLinkId = $input['tracking_link_id'];
    $score = intval($input['score']);
    $interactions = $input['interactions'] ?? [];

    $result = $trackingManager->recordScore($trackingLinkId, $score, $interactions);

    sendJSON([
        'success' => true,
        'score' => $result['score'],
        'status' => $result['status'],
        'message' => 'Score recorded successfully'
    ]);

} catch (Exception $e) {
    error_log("Record Score Error: " . $e->getMessage());
    sendJSON([
        'error' => 'Failed to record score',
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal server error'
    ], 500);
}
