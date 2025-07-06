<?php
/**
 * Simple test to verify var_send extension functionality
 */

require_once 'vendor/autoload.php';
require_once 'tests/TestServer.php';

use VarSend\Tests\SimpleTestServer;

echo "🧪 Simple var_send Extension Test\n";
echo "================================\n";

// Check extension
if (!extension_loaded('var_send')) {
    echo "❌ var_send extension not loaded\n";
    exit(1);
}

if (!function_exists('var_send')) {
    echo "❌ var_send function not available\n";
    exit(1);
}

echo "✅ Extension loaded and function available\n";

// Start test server
$server = new SimpleTestServer('127.0.0.1', 9002);
echo "🚀 Starting test server...\n";
$server->start();
sleep(1); // Give server time to start

// Configure var_send
ini_set('var_send.enabled', '1');
ini_set('var_send.server_host', '127.0.0.1');
ini_set('var_send.server_port', '9002');

echo "📡 Sending test data...\n";

// Test basic data types
$result1 = var_send("Hello World!");
$result2 = var_send(42);
$result3 = var_send(['test' => 'array', 'numbers' => [1, 2, 3]]);
$result4 = var_send((object)['x' => 1, 'y' => 2]);

echo "Send results: " . ($result1 ? 'OK' : 'FAIL') . " " . 
                      ($result2 ? 'OK' : 'FAIL') . " " . 
                      ($result3 ? 'OK' : 'FAIL') . " " . 
                      ($result4 ? 'OK' : 'FAIL') . "\n";

// Wait for messages
echo "⏳ Waiting for messages...\n";
sleep(1);

$messages = $server->getMessages();
echo "📨 Received " . count($messages) . " messages\n";

if (count($messages) > 0) {
    echo "✅ First message preview:\n";
    echo substr($messages[0]['data'], 0, 200) . "...\n";
} else {
    echo "❌ No messages received\n";
}

$server->stop();
echo "🏁 Test completed\n";