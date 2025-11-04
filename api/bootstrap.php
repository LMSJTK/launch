<?php
/**
 * Bootstrap file for API endpoints
 * Loads configuration and initializes core classes
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

// CORS headers for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/../config/config.example.php';
}
$config = require $configPath;

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Autoload classes
spl_autoload_register(function ($className) {
    $file = __DIR__ . '/../lib/' . $className . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize core classes
try {
    $db = Database::getInstance($config['database']);
    $claudeAPI = new ClaudeAPI($config['claude']);
    $sns = new AWSSNS($config['aws_sns']);
    $contentProcessor = new ContentProcessor($db, $claudeAPI, $config['content']['upload_dir']);
    $trackingManager = new TrackingManager($db, $sns);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'System initialization failed', 'message' => $e->getMessage()]);
    exit;
}

/**
 * Helper function to send JSON response
 */
function sendJSON($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Helper function to get JSON input
 */
function getJSONInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Helper function to validate required fields
 */
function validateRequired($data, $required) {
    $missing = [];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        sendJSON([
            'error' => 'Missing required fields',
            'fields' => $missing
        ], 400);
    }
}
