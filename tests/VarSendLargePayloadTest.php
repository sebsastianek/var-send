<?php

namespace VarSend\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Large payload and stress tests for var_send extension
 */
class VarSendLargePayloadTest extends TestCase
{
    private SimpleTestServer $server;

    protected function setUp(): void
    {
        $this->server = new SimpleTestServer('127.0.0.1', 9002);
        $this->server->start();
        
        ini_set('var_send.server_host', '127.0.0.1');
        ini_set('var_send.server_port', '9002');
        ini_set('var_send.enabled', '1');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    /**
     * @group large
     */
    public function testLargeString(): void
    {
        $this->server->clearMessages();

        // Create a 1MB string
        $largeString = str_repeat('A', 1024 * 1024);
        
        var_send($largeString);

        $this->assertTrue(
            $this->server->waitForMessages(1, 10000), // 10 second timeout for large data
            'Should receive 1 message for large string'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Type: string', $data);
        $this->assertStringContainsString('Value: ' . substr($largeString, 0, 100), $data);
    }

    /**
     * @group large
     */
    public function testLargeArray(): void
    {
        $this->server->clearMessages();

        // Create large array with 10,000 elements
        $largeArray = [];
        for ($i = 0; $i < 10000; $i++) {
            $largeArray["key_$i"] = "value_$i" . str_repeat("x", 50);
        }

        var_send($largeArray);

        $this->assertTrue(
            $this->server->waitForMessages(1, 15000), // 15 second timeout
            'Should receive 1 message for large array'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Type: array', $data);
        $this->assertStringContainsString('Array with 10000 elements', $data);
    }

    /**
     * @group large
     */
    public function testDeeplyNestedArray(): void
    {
        $this->server->clearMessages();

        // Create deeply nested array (100 levels)
        $deepArray = [];
        $current = &$deepArray;
        
        for ($i = 0; $i < 100; $i++) {
            $current["level_$i"] = [];
            $current["data_$i"] = "Level $i data";
            $current = &$current["level_$i"];
        }

        var_send($deepArray);

        $this->assertTrue(
            $this->server->waitForMessages(1, 10000),
            'Should receive 1 message for deeply nested array'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Type: array', $data);
        $this->assertStringContainsString('level_0', $data);
        $this->assertStringContainsString('level_50', $data); // Should contain mid-level data
    }

    /**
     * @group large
     */
    public function testLargeObjectWithManyProperties(): void
    {
        $this->server->clearMessages();

        // Create object with many properties
        $largeObject = new \stdClass();
        for ($i = 0; $i < 1000; $i++) {
            $propertyName = "property_$i";
            $largeObject->$propertyName = "Value for property $i " . str_repeat("data", 20);
        }

        var_send($largeObject);

        $this->assertTrue(
            $this->server->waitForMessages(1, 10000),
            'Should receive 1 message for large object'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Type: object', $data);
        $this->assertStringContainsString('Object of class \'stdClass\'', $data);
    }

    /**
     * @group stress
     */
    public function testRapidSuccessiveMessages(): void
    {
        $this->server->clearMessages();

        $messageCount = 20; // Reduced for more reliable testing
        
        // Send many messages rapidly - one variable per call for predictable count
        for ($i = 0; $i < $messageCount; $i++) {
            var_send("Rapid message #$i");
            usleep(5000); // 5ms delay between messages
        }

        $this->assertTrue(
            $this->server->waitForMessages($messageCount, 10000),
            "Should receive $messageCount messages for rapid succession test"
        );

        $messages = $this->server->getMessages();
        $this->assertCount($messageCount, $messages, "Should have exactly $messageCount messages");

        // Verify message ordering and content
        for ($i = 0; $i < min(5, $messageCount); $i++) {
            $data = $messages[$i]['data'];
            $this->assertStringContainsString("Rapid message #$i", $data);
            $this->assertStringContainsString('Variable #1', $data); // Single variable per message
            $this->assertStringContainsString('Type: string', $data);
        }
    }

    /**
     * @group stress
     */
    public function testMixedLargeDataTypes(): void
    {
        $this->server->clearMessages();

        // Mix of large data types in single call
        $largeString = str_repeat('Mixed test data ', 10000); // ~160KB
        $largeArray = array_fill(0, 5000, 'array element');
        $largeObject = new \stdClass();
        for ($i = 0; $i < 500; $i++) {
            $largeObject->{"prop$i"} = "Property value $i";
        }

        var_send($largeString, $largeArray, $largeObject);

        $this->assertTrue(
            $this->server->waitForMessages(3, 15000),
            'Should receive 3 separate messages for mixed large data types'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(3, $messages);
        
        // First message: large string (Variable #1)
        $this->assertStringContainsString('Variable #1', $messages[0]['data']);
        $this->assertStringContainsString('Type: string', $messages[0]['data']);
        
        // Second message: large array (Variable #2)
        $this->assertStringContainsString('Variable #2', $messages[1]['data']);
        $this->assertStringContainsString('Type: array', $messages[1]['data']);
        
        // Third message: large object (Variable #3)
        $this->assertStringContainsString('Variable #3', $messages[2]['data']);
        $this->assertStringContainsString('Type: object', $messages[2]['data']);
    }

    /**
     * @group performance
     */
    public function testPerformanceWithLargePayload(): void
    {
        $this->server->clearMessages();

        $startTime = microtime(true);
        
        // Create moderately large payload (~100KB)
        $payload = [
            'string' => str_repeat('Performance test ', 1000),
            'array' => array_fill(0, 1000, 'test data'),
            'nested' => [
                'level1' => array_fill(0, 500, 'nested data'),
                'level2' => ['deep' => array_fill(0, 200, 'deep data')]
            ]
        ];

        var_send($payload);
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->assertTrue(
            $this->server->waitForMessages(1, 5000),
            'Should receive message within reasonable time'
        );

        // Performance assertion - should complete within 1 second for 100KB payload
        $this->assertLessThan(1000, $executionTime, 
            'var_send should complete within 1 second for 100KB payload');

        $messages = $this->server->getMessages();
        $this->assertCount(1, $messages);
        
        $data = $messages[0]['data'];
        $this->assertStringContainsString('Performance test', $data);
    }

    /**
     * @group edge
     */
    public function testEmptyAndBoundaryValues(): void
    {
        $this->server->clearMessages();

        var_send(
            '', // Empty string
            [], // Empty array
            (object)[], // Empty object
            0, // Zero
            PHP_INT_MAX, // Maximum integer
            PHP_INT_MIN, // Minimum integer
            PHP_FLOAT_MAX, // Maximum float
            -PHP_FLOAT_MAX // Minimum float
        );

        $this->assertTrue(
            $this->server->waitForMessages(8, 5000),
            'Should receive 8 separate messages for boundary values'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(8, $messages);
        
        // Verify each message contains the expected variable number and type
        $expectedTypes = ['string', 'array', 'object', 'integer', 'integer', 'integer', 'double', 'double'];
        
        for ($i = 0; $i < 8; $i++) {
            $data = $messages[$i]['data'];
            $this->assertStringContainsString("Variable #" . ($i + 1), $data);
            $this->assertStringContainsString("Type: {$expectedTypes[$i]}", $data);
        }
    }
}