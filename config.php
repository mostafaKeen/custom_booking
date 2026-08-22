<?php
/**
 * Bitrix24 Custom Booking Widget Configuration
 */

// Application Environment & Debug
define('APP_DEBUG', true);

// Database Configuration
// DB_DRIVER can be 'sqlite' or 'mysql'
define('DB_DRIVER', 'sqlite');
define('DB_FILE', __DIR__ . '/data/booking.sqlite');

// MySQL Settings (used if DB_DRIVER === 'mysql')
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'b24_custom_booking');
define('DB_USER', 'root');
define('DB_PASS', '');

// Bitrix24 Application Settings
define('C_REST_CLIENT_ID', 'local.66c5d9a0e1a2b3.12345678'); // Replace with your B24 App Client ID
define('C_REST_CLIENT_SECRET', 'SecretKeyHere1234567890'); // Replace with your B24 App Client Secret

// Booking Default Settings
define('DEFAULT_WORKING_START', '09:00');
define('DEFAULT_WORKING_END', '18:00');
define('DEFAULT_SLOT_DURATION', 30); // Duration in minutes
define('DEFAULT_BUFFER_TIME', 5);    // Buffer between slots in minutes
define('DEFAULT_SHARED_CALENDAR_ID', 0); // 0 or specific shared calendar ID in Bitrix24

if (APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}
