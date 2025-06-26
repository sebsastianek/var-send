<?php

namespace VarSend\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Comprehensive E2E tests for var_send extension
 */
class VarSendExtensionTest extends TestCase
{
    private SimpleTestServer $server;

    protected function setUp(): void
    {
        $this->server = new SimpleTestServer('127.0.0.1', 9002);
        $this->server->start();
        
        // Configure var_send to use test server
        ini_set('var_send.server_host', '127.0.0.1');
        ini_set('var_send.server_port', '9002');
        ini_set('var_send.enabled', '1');
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    /**
     * @group basic
     */
    public function testExtensionIsLoaded(): void
    {
        $this->assertTrue(extension_loaded('var_send'), 'var_send extension should be loaded');
        $this->assertTrue(function_exists('var_send'), 'var_send function should exist');
    }

    /**
     * @group basic
     */
    public function testBasicDataTypes(): void
    {
        $this->server->clearMessages();

        // Test different data types
        var_send("test string");
        var_send(42);
        var_send(3.14);
        var_send(true);
        var_send(false);
        var_send(null);

        $this->assertTrue(
            $this->server->waitForMessages(6, 2000),
            'Should receive 6 messages for basic data types'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(6, $messages, 'Should have exactly 6 messages');

        // Verify string message
        $this->assertStringContainsString('Type: string', $messages[0]['data']);
        $this->assertStringContainsString('Value: test string', $messages[0]['data']);

        // Verify integer message
        $this->assertStringContainsString('Type: integer', $messages[1]['data']);
        $this->assertStringContainsString('Value: 42', $messages[1]['data']);

        // Verify float message
        $this->assertStringContainsString('Type: double', $messages[2]['data']);
        $this->assertStringContainsString('Value: 3.14', $messages[2]['data']);

        // Verify boolean true
        $this->assertStringContainsString('Type: boolean(true)', $messages[3]['data']);
        $this->assertStringContainsString('Value: 1', $messages[3]['data']);

        // Verify boolean false
        $this->assertStringContainsString('Type: boolean(false)', $messages[4]['data']);
        $this->assertStringContainsString('Value:', $messages[4]['data']);

        // Verify null
        $this->assertStringContainsString('Type: NULL', $messages[5]['data']);
        $this->assertStringContainsString('Value:', $messages[5]['data']);
    }

    /**
     * @group arrays
     */
    public function testArrays(): void
    {
        $this->server->clearMessages();

        $simpleArray = [1, 2, 3];
        $associativeArray = ['name' => 'John', 'age' => 30];
        $nestedArray = [
            'user' => ['name' => 'Jane', 'email' => 'jane@example.com'],
            'settings' => ['theme' => 'dark', 'notifications' => true]
        ];

        var_send($simpleArray);
        var_send($associativeArray);
        var_send($nestedArray);

        $this->assertTrue(
            $this->server->waitForMessages(3, 2000),
            'Should receive 3 messages for arrays'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(3, $messages);

        // Verify simple array
        $this->assertStringContainsString('Type: array', $messages[0]['data']);
        $this->assertStringContainsString('Array with 3 elements', $messages[0]['data']);

        // Verify associative array
        $this->assertStringContainsString('Type: array', $messages[1]['data']);
        $this->assertStringContainsString('Array with 2 elements', $messages[1]['data']);
        $this->assertStringContainsString('name', $messages[1]['data']);
        $this->assertStringContainsString('age', $messages[1]['data']);

        // Verify nested array
        $this->assertStringContainsString('Type: array', $messages[2]['data']);
        $this->assertStringContainsString('user', $messages[2]['data']);
        $this->assertStringContainsString('settings', $messages[2]['data']);
    }

    /**
     * @group objects
     */
    public function testObjects(): void
    {
        $this->server->clearMessages();

        $simpleObject = (object)['x' => 1, 'y' => 2];
        $complexObject = new \DateTime('2024-01-01 12:00:00');

        var_send($simpleObject);
        var_send($complexObject);

        $this->assertTrue(
            $this->server->waitForMessages(2, 2000),
            'Should receive 2 messages for objects'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(2, $messages);

        // Verify simple object
        $this->assertStringContainsString('Type: object', $messages[0]['data']);
        $this->assertStringContainsString('Object of class \'stdClass\'', $messages[0]['data']);

        // Verify DateTime object
        $this->assertStringContainsString('Type: object', $messages[1]['data']);
        $this->assertStringContainsString('Object of class \'DateTime\'', $messages[1]['data']);
    }

    /**
     * @group multiple
     */
    public function testMultipleArguments(): void
    {
        $this->server->clearMessages();

        var_send("first", 42, ['array'], (object)['obj' => 'value']);

        $this->assertTrue(
            $this->server->waitForMessages(4, 2000),
            'Should receive 4 separate messages for 4 variables'
        );

        $messages = $this->server->getMessages();
        $this->assertCount(4, $messages, 'Should have 4 separate messages');

        // First message: string variable (Variable #1)
        $this->assertStringContainsString('Variable #1', $messages[0]['data']);
        $this->assertStringContainsString('Type: string', $messages[0]['data']);
        $this->assertStringContainsString('first', $messages[0]['data']);

        // Second message: integer variable (Variable #2)  
        $this->assertStringContainsString('Variable #2', $messages[1]['data']);
        $this->assertStringContainsString('Type: integer', $messages[1]['data']);
        $this->assertStringContainsString('42', $messages[1]['data']);

        // Third message: array variable (Variable #3)
        $this->assertStringContainsString('Variable #3', $messages[2]['data']);
        $this->assertStringContainsString('Type: array', $messages[2]['data']);

        // Fourth message: object variable (Variable #4)
        $this->assertStringContainsString('Variable #4', $messages[3]['data']);
        $this->assertStringContainsString('Type: object', $messages[3]['data']);
    }
}