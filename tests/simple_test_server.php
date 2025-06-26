<?php
$host = $argv[1] ?? "127.0.0.1";
$port = (int)($argv[2] ?? 9002);
$messageFile = sys_get_temp_dir() . "/var_send_test_messages.json";

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    exit(1);
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($socket, $host, $port)) {
    exit(1);
}

if (!socket_listen($socket, 5)) {
    exit(1);
}

while (true) {
    $client = socket_accept($socket);
    if ($client === false) {
        continue;
    }

    try {
        while (true) {
            $lengthPrefix = socket_read($client, 4, PHP_BINARY_READ);
            if ($lengthPrefix === false || strlen($lengthPrefix) < 4) {
                break;
            }

            $unpacked = unpack("Nlen", $lengthPrefix);
            $messageLength = $unpacked["len"];

            if ($messageLength > 0) {
                $messageData = "";
                $bytesRemaining = $messageLength;

                while ($bytesRemaining > 0) {
                    $chunk = socket_read($client, $bytesRemaining, PHP_BINARY_READ);
                    if ($chunk === false || strlen($chunk) === 0) {
                        break 2;
                    }
                    $messageData .= $chunk;
                    $bytesRemaining -= strlen($chunk);
                }

                if ($bytesRemaining === 0) {
                    $messages = [];
                    if (file_exists($messageFile)) {
                        $content = file_get_contents($messageFile);
                        if ($content) {
                            $messages = json_decode($content, true) ?: [];
                        }
                    }
                    
                    $messages[] = [
                        "timestamp" => microtime(true),
                        "data" => $messageData
                    ];
                    
                    file_put_contents($messageFile, json_encode($messages));
                }
            }
        }
    } catch (Exception $e) {
        error_log("Test server error: " . $e->getMessage());
    } finally {
        socket_close($client);
    }
}
