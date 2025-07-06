<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!extension_loaded('var_send')) {
    dl('var_send.so'); // Try to load it if not already
}

if (!function_exists('var_send')) {
    echo "var_send function does not exist. Extension not loaded correctly.\n";
    exit(1);
}

echo "var_send extension seems loaded.\n";

// Test 1: Large array
echo "Sending large array...\n";
$largeArray = [];
for ($i = 0; $i < 1000; $i++) {
    $largeArray["item_".$i] = "This is string value for item " . $i . " " . str_repeat("padding ", 20);
    if ($i % 100 === 0) {
        $largeArray["nested_".$i] = range(0, 50);
    }
}
var_send($largeArray, "Completed sending large array");

// Test 2: Large string
echo "Sending large string...\n";
$largeString = str_repeat("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+", 5000); // ~300KB
var_send($largeString, "Completed sending large string");

// Test 3: Rapid succession of small messages
echo "Sending multiple small messages rapidly...\n";
for ($i = 0; $i < 50; $i++) {
    var_send("Rapid message #".$i, ['count' => $i, 'timestamp' => microtime(true)]);
    usleep(10000); // 10ms delay
}
var_send("Completed rapid messages test.");


// Test 4: Object with lots of properties
echo "Sending object with many properties...\n";
class BigObject {
    private array $properties = [];

    public function __construct() {
        for ($i = 0; $i < 500; $i++) {
            $propName = "property" . $i;
            $this->properties[$propName] = "This is value for property " . $i . " " . str_repeat("obj_padding ", 10);
        }
    }

    public function __get($name) {
        return $this->properties[$name] ?? null;
    }

    public function __set($name, $value) {
        $this->properties[$name] = $value;
    }

    public function __isset($name) {
        return isset($this->properties[$name]);
    }
}
$bigObject = new BigObject();
var_send($bigObject, "Completed sending big object");

// Test 5: Deeply nested array
echo "Sending deeply nested array...\n";
$deepArray = [];
$currentLevel = &$deepArray;
for ($i = 0; $i < 30; $i++) { // 30 levels deep
    $currentLevel['level_'. $i] = [];
    $currentLevel['data_'. $i] = "Data for level " . $i;
    $currentLevel = &$currentLevel['level_'. $i];
}
var_send($deepArray, "Completed sending deeply nested array");


// Test 6: Sending different data types in one call
echo "Sending multiple different data types...\n";
$mixedDataInt = 12345;
$mixedDataFloat = 123.456;
$mixedDataBool = true;
$mixedDataNull = null;
$mixedDataSimpleArray = ['a', 'b', 'c'];
var_send(
    $mixedDataInt,
    $mixedDataFloat,
    $mixedDataBool,
    $mixedDataNull,
    $mixedDataSimpleArray,
    "Hello from mixed data send!",
    new DateTime()
);
var_send("All stress tests completed.");

echo "All stress test var_send calls have been made. Check the debug server.\n";
?>
