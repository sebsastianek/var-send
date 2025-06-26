<?php

namespace VarSend\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Error handling and edge case tests for var_send extension
 */
class VarSendErrorHandlingTest extends TestCase
{
    private SimpleTestServer $server;

    protected function setUp(): void
    {
        $this->server = new SimpleTestServer('127.0.0.1', 9002);
        
        ini_set('var_send.server_host', '127.0.0.1');
        ini_set('var_send.server_port', '9002');
        ini_set('var_send.enabled', '1');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    /**
     * @group config
     */
    public function testConfigurationOptions(): void
    {
        // Test enabled/disabled functionality
        ini_set('var_send.enabled', '0');
        $result = var_send('test');
        $this->assertFalse($result, 'var_send should return false when disabled');

        ini_set('var_send.enabled', '1');
        $this->server->start();
        $result = var_send('test');
        $this->assertTrue($result, 'var_send should return true when enabled and connected');
    }

    /**
     * @group connection
     */
    public function testConnectionFailure(): void
    {
        // Ensure server is not running
        $this->server->stop();
        
        // Configure to connect to non-existent server
        ini_set('var_send.server_host', '127.0.0.1');
        ini_set('var_send.server_port', '9999'); // Non-existent port
        ini_set('var_send.enabled', '1');

        // Should return false when connection fails (suppress expected warning)
        $result = @var_send('test message');
        $this->assertFalse($result, 'var_send should return false when connection fails');
    }

    /**
     * @group resources
     */
    public function testResourceHandling(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        // Test with file resource
        $tempFile = tmpfile();
        $this->assertIsResource($tempFile);

        var_send($tempFile);

        $this->assertTrue(
            $this->server->waitForMessages(1, 2000),
            'Should receive message for resource'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Type: resource', $data);
        $this->assertStringContainsString('Resource ID', $data);

        fclose($tempFile);
    }

    /**
     * @group unicode
     */
    public function testUnicodeAndSpecialCharacters(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        $unicodeStrings = [
            'ASCII: Hello World',
            'UTF-8: Héllo Wörld',
            'Emoji: 🚀 🌟 💫',
            'Chinese: 你好世界',
            'Arabic: مرحبا بالعالم',
            'Russian: Привет мир',
            'Special chars: @#$%^&*()[]{}|\\:";\'<>?,./',
            'Null byte: ' . "\0" . 'after null',
            'Control chars: ' . "\t\n\r\f\v",
        ];

        foreach ($unicodeStrings as $index => $str) {
            var_send($str);
        }

        $this->assertTrue(
            $this->server->waitForMessages(count($unicodeStrings), 5000),
            'Should receive all unicode string messages'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(count($unicodeStrings), $messages);

        // Verify each message contains the expected content
        foreach ($messages as $index => $message) {
            $this->assertStringContainsString('Type: string', $message['data']);
        }
    }

    /**
     * @group circular
     */
    public function testCircularReferences(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        // Create circular reference
        $obj1 = new \stdClass();
        $obj2 = new \stdClass();
        $obj1->ref = $obj2;
        $obj2->ref = $obj1;

        // Extension handles circular references by converting them to NULL
        $result = @var_send($obj1); // Suppress any warnings
        $this->assertTrue($result, 'var_send should handle circular references gracefully');
        
        // Should receive message with object data (circular refs converted to NULL)
        $this->assertTrue(
            $this->server->waitForMessages(1, 3000),
            'Should receive message with circular reference handled'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Type: object', $data);
        $this->assertStringContainsString('stdClass', $data);
    }

    /**
     * @group memory
     */
    public function testMemoryIntensiveOperations(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        // Test with very large string (5MB)
        $memoryBefore = memory_get_usage(true);
        $veryLargeString = str_repeat('M', 5 * 1024 * 1024);
        
        var_send($veryLargeString);
        
        $memoryAfter = memory_get_usage(true);
        
        // Clean up large string
        unset($veryLargeString);

        $this->assertTrue(
            $this->server->waitForMessages(1, 30000), // 30 second timeout for 5MB
            'Should handle very large string (5MB)'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        // Memory usage should not grow excessively (allow up to 10MB growth)
        $memoryGrowth = $memoryAfter - $memoryBefore;
        $this->assertLessThan(10 * 1024 * 1024, $memoryGrowth, 
            'Memory growth should be reasonable for large payloads');
    }

    /**
     * @group types
     */
    public function testAllPhpTypes(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        // Create variables of all PHP types
        $variables = [
            null,                           // NULL
            true,                          // boolean true
            false,                         // boolean false
            42,                           // integer
            3.14159,                      // float
            'string value',               // string
            [1, 2, 3],                   // indexed array
            ['a' => 1, 'b' => 2],        // associative array
            new \stdClass(),             // object
            tmpfile(),                   // resource
        ];

        foreach ($variables as $var) {
            var_send($var);
        }

        $expectedMessages = count($variables);
        $this->assertTrue(
            $this->server->waitForMessages($expectedMessages, 5000),
            "Should receive $expectedMessages messages for all PHP types"
        );

        $messages = $this->server->getMessages();
        $this->assertCount($expectedMessages, $messages);

        // Verify type detection
        $expectedTypes = [
            'NULL', 'boolean(true)', 'boolean(false)', 'integer', 
            'double', 'string', 'array', 'array', 'object', 'resource'
        ];

        foreach ($messages as $index => $message) {
            $this->assertStringContainsString("Type: {$expectedTypes[$index]}", $message['data'],
                "Message $index should contain correct type: {$expectedTypes[$index]}");
        }

        // Close resource
        fclose($variables[9]);
    }

    /**
     * @group concurrent
     */
    public function testConcurrentConnections(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        // Simulate concurrent var_send calls
        $messageCount = 20;

        for ($i = 0; $i < $messageCount; $i++) {
            var_send("Concurrent message $i"); // Send only one variable per call
            usleep(1000); // 1ms delay between sends
        }

        $this->assertTrue(
            $this->server->waitForMessages($messageCount, 10000),
            "Should receive all $messageCount concurrent messages"
        );

        $messages = $this->server->getMessages();
        $this->assertCount($messageCount, $messages);

        // Verify all messages are unique
        $messageContents = array_map(function($msg) {
            return $msg['data'];
        }, $messages);

        $uniqueMessages = array_unique($messageContents);
        $this->assertCount($messageCount, $uniqueMessages, 
            'All messages should be unique');
    }

    /**
     * @group validation
     */
    public function testParameterValidationNoArgs(): void
    {
        // Test with no parameters (should throw ArgumentCountError)
        $this->expectException(\ArgumentCountError::class);
        var_send(); // This should throw an exception
    }

    /**
     * @group validation
     */
    public function testParameterValidationValid(): void
    {
        $this->server->start();
        $this->server->clearMessages();

        // Test with valid parameters
        $result = var_send('valid');
        $this->assertTrue($result, 'var_send with valid parameters should return true');

        // Verify the valid message was received
        $this->assertTrue(
            $this->server->waitForMessages(1, 2000),
            'Should receive the valid message'
        );
    }
}