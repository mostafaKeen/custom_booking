<?php
/**
 * Bitrix24 Custom Booking Widget Configuration
 */

// Load local overrides if present (e.g. C_REST_CLIENT_SECRET, custom DB, etc.)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Application Environment & Debug
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);

// Database Configuration (DB_DRIVER can be 'sqlite' or 'mysql')
if (!defined('DB_DRIVER')) define('DB_DRIVER', 'sqlite');
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/data/booking.sqlite');

// MySQL Settings (used if DB_DRIVER === 'mysql')
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_NAME')) define('DB_NAME', 'b24_custom_booking');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// Bitrix24 Application Settings Defaults
if (!defined('C_REST_CLIENT_ID')) define('C_REST_CLIENT_ID', 'local.66c5d9a0e1a2b3.12345678');
if (!defined('C_REST_CLIENT_SECRET')) define('C_REST_CLIENT_SECRET', 'SecretKeyHere1234567890');

// Booking Default Settings
if (!defined('DEFAULT_WORKING_START')) define('DEFAULT_WORKING_START', '09:00');
if (!defined('DEFAULT_WORKING_END')) define('DEFAULT_WORKING_END', '18:00');
if (!defined('DEFAULT_SLOT_DURATION')) define('DEFAULT_SLOT_DURATION', 30); // Duration in minutes
if (!defined('DEFAULT_BUFFER_TIME')) define('DEFAULT_BUFFER_TIME', 5);       // Buffer between slots in minutes
if (!defined('DEFAULT_SHARED_CALENDAR_ID')) define('DEFAULT_SHARED_CALENDAR_ID', 0); // 0 or specific shared calendar ID in Bitrix24

if (APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

