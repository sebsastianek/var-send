<?php
/**
 * PHPUnit bootstrap file for var_send extension tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Manually include test helper classes
require_once __DIR__ . '/TestServer.php';

// Check if var_send extension is loaded
if (!extension_loaded('var_send')) {
    throw new Exception('var_send extension is not loaded. Make sure to run tests with: php -d extension=./modules/var_send.so vendor/bin/phpunit');
}

// Check if var_send function exists
if (!function_exists('var_send')) {
    throw new Exception('var_send function is not available');
}

echo "var_send extension loaded successfully\n";
echo "Extension version: " . phpversion('var_send') . "\n";
