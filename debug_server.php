<?php
/**
 * Simple TCP server to receive var_send debug data
 * Run with: php debug_server.php
 */

$host = '0.0.0.0';
$port = 9001;

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    echo "Error: " . socket_strerror(socket_last_error()) . "\n";
    exit(1);
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($socket, $host, $port)) {
    echo "Error binding: " . socket_strerror(socket_last_error($socket)) . "\n";
    exit(1);
}

if (!socket_listen($socket, 5)) {
    echo "Error listening: " . socket_strerror(socket_last_error($socket)) . "\n";
    exit(1);
}

echo "Debug server started on {$host}:{$port}\n";
echo "Waiting for connections...\n";

while (true) {
    $client = socket_accept($socket);
    if ($client === false) {
        echo "Error accepting connection: " . socket_strerror(socket_last_error($socket)) . "\n";
        continue;
    }

    socket_getpeername($client, $address, $port);
    echo "New connection from {$address}:{$port}\n";

    $data = '';
    while ($buf = socket_read($client, 2048, PHP_BINARY_READ)) {

        $data .= $buf;
        // For simplicity, we assume each message is complete on its own
        if (strlen($buf) < 2048) {
            break;
        }
    }

    if (!empty($data)) {
        $timestamp = date('Y-m-d H:i:s');
        echo "\n===== VAR_SEND [{$timestamp}] FROM {$address}:{$port} =====\n";
        echo $data . "\n";
        echo "=====" . str_repeat("=", strlen($timestamp) + strlen($address) + strlen((string)$port) + 19) . "\n\n";
    }

    socket_close($client);
}
