<?php
/**
 * Example usage of var_send extension
 */
$data = [
    'string' => 'Hello World',
    'number' => 42,
    'boolean' => true,
    'array' => [1, 2, 3, 4, 5],
    'nested' => [
        'a' => 'apple',
        'b' => 'banana',
        'c' => [
            'complex' => (object)['x' => 1, 'y' => 2],
            'date' => new DateTime()
        ]
    ]
];

class TestClass {
    public $publicVar = 'public value';
    protected $protectedVar = 'protected value';
    private $privateVar = 'private value';

    public function testMethod($arg) {
        var_send($arg);
        return $arg;
    }
}

$object = new TestClass();

echo "Sending string to debug server...\n";
var_send("This is a test string");

echo "Sending number to debug server...\n";
var_send(42);

echo "Sending complex array to debug server...\n";
var_send($data);

echo "Sending object to debug server...\n";
var_send($object);

echo "Using var_send in a method...\n";
$object->testMethod("Method argument");

// Testing multiple arguments
echo "Sending multiple variables at once...\n";
var_send($data, $object, "Multiple arguments");

echo "Done! Check your debug server for the output.\n";
