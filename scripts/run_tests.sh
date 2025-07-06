#!/bin/bash

# E2E Test Runner for var_send extension
set -e

echo "🧪 Running var_send Extension E2E Tests"
echo "========================================"

# Check if extension module exists
if [ ! -f "./modules/var_send.so" ]; then
    echo "❌ Extension not found. Please compile first with: make"
    exit 1
fi

# Check if PHPUnit is installed
if [ ! -f "./vendor/bin/phpunit" ]; then
    echo "❌ PHPUnit not found. Please install with: php ../composer.phar install"
    exit 1
fi

# Kill any existing test servers
echo "🧹 Cleaning up any existing test servers..."
pkill -f 'simple_test_server.php' 2>/dev/null || true

# Function to run test group
run_test_group() {
    local group=$1
    local description=$2
    
    echo ""
    echo "📝 Running $description..."
    echo "----------------------------------------"
    
    php -d extension=./modules/var_send.so \
        -d var_send.enabled=1 \
        -d var_send.server_host=127.0.0.1 \
        -d var_send.server_port=9002 \
        vendor/bin/phpunit \
        --group="$group" \
        --testdox \
        tests/
}

# Run test groups
echo "🚀 Starting test execution..."

run_test_group "basic" "Basic Data Type Tests"
run_test_group "arrays" "Array Tests" 
run_test_group "objects" "Object Tests"
run_test_group "multiple" "Multiple Arguments Tests"
run_test_group "large" "Large Payload Tests"
run_test_group "stress" "Stress Tests"
run_test_group "performance" "Performance Tests"
run_test_group "edge" "Edge Case Tests"
run_test_group "config" "Configuration Tests"
run_test_group "connection" "Connection Tests"
run_test_group "resources" "Resource Handling Tests"
run_test_group "unicode" "Unicode Tests"
run_test_group "circular" "Circular Reference Tests"
run_test_group "memory" "Memory Tests"
run_test_group "types" "All PHP Types Tests"
run_test_group "concurrent" "Concurrent Tests"
run_test_group "validation" "Parameter Validation Tests"

echo ""
echo "🏁 Running complete test suite..."
echo "=================================="

php -d extension=./modules/var_send.so \
    -d var_send.enabled=1 \
    -d var_send.server_host=127.0.0.1 \
    -d var_send.server_port=9002 \
    vendor/bin/phpunit \
    --testdox \
    tests/

echo ""
echo "✅ All tests completed!"
echo "📊 Test Results Summary:"
echo "  - Basic functionality: Data types, arrays, objects"
echo "  - Large payloads: 1MB+ strings, 10K+ element arrays"
echo "  - Stress testing: Rapid succession, concurrent connections"
echo "  - Error handling: Connection failures, edge cases"
echo "  - Performance: Memory usage, execution time"
echo "  - Unicode support: Multi-byte characters, special chars"

# Cleanup
echo ""
echo "🧹 Cleaning up test servers..."
pkill -f 'simple_test_server.php' 2>/dev/null || true

echo "🎉 Testing complete!"