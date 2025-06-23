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

    try {
        while (true) {
            // Read the 4-byte length prefix
            $lengthPrefix = socket_read($client, 4, PHP_BINARY_READ);

            if ($lengthPrefix === false || strlen($lengthPrefix) < 4) {
                if (strlen($lengthPrefix) === 0 && socket_last_error($client) == 0) {
                    // Graceful client disconnect, no more messages
                    echo "Client {$address}:{$port} disconnected gracefully (no more messages).\n";
                } else if (strlen($lengthPrefix) > 0 && strlen($lengthPrefix) < 4) {
                    echo "Error: Incomplete length prefix received from {$address}:{$port}. Expected 4 bytes, got " . strlen($lengthPrefix) . ". Client may have disconnected.\n";
                } else {
                    $lastError = socket_last_error($client);
                    // 104: Connection reset by peer (client closed abruptly)
                    // 0 can also mean client closed socket after sending all data.
                    if ($lastError != 0 && $lastError != 104) {
                        echo "socket_read() for length failed for {$address}:{$port}: reason: " . socket_strerror($lastError) . "\n";
                    } else if ($lastError == 104) {
                        echo "Client {$address}:{$port} disconnected (connection reset by peer).\n";
                    } else {
                        // Potentially clean disconnect if this was the first read attempt on a closed socket
                        echo "Client {$address}:{$port} appears to have disconnected.\n";
                    }
                }
                break; // Break from inner while loop (message reading loop)
            }

            // Unpack the length (network byte order - unsigned long)
            $unpacked = unpack('Nlen', $lengthPrefix);
            $messageLength = $unpacked['len'];

            if ($messageLength > 0) {
                $messageData = '';
                $bytesRemaining = $messageLength;

                while ($bytesRemaining > 0) {
                    $chunk = socket_read($client, $bytesRemaining, PHP_BINARY_READ);
                    if ($chunk === false || strlen($chunk) === 0) {
                        $lastError = socket_last_error($client);
                        if ($lastError != 0 && $lastError != 104) {
                           echo "socket_read() for message data failed for {$address}:{$port}: reason: " . socket_strerror($lastError) . "\n";
                        }
                        echo "Error: Client {$address}:{$port} disconnected while sending message body. Expected $messageLength bytes, received " . strlen($messageData) . "\n";
                        break 2; // Break from both while loops (message reading and client connection loop)
                    }
                    $messageData .= $chunk;
                    $bytesRemaining -= strlen($chunk);
                }

                if ($bytesRemaining === 0) {
                    $timestamp = date('Y-m-d H:i:s');
                    echo "\n===== VAR_SEND [{$timestamp}] FROM {$address}:{$port} ({$messageLength} bytes) =====\n";
                    echo $messageData . "\n"; // The data itself usually ends with a newline from the C extension
                    echo "=====" . str_repeat("=", strlen($timestamp) + strlen($address) + strlen((string)$port) + strlen((string)$messageLength) + 27) . "\n\n";
                }
            } else if ($messageLength === 0) {
                // This case should ideally not happen if client always sends data.
                echo "Received message with zero length from {$address}:{$port}.\n";
            }
        } // End of message reading loop
    } catch (Exception $e) {
        echo "Error during client {$address}:{$port} communication: " . $e->getMessage() . "\n";
    } finally {
        echo "Closing connection from {$address}:{$port}\n";
        socket_close($client);
    }
}
