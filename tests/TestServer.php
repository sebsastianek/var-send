<?php

namespace VarSend\Tests;

/**
 * Test server helper for capturing var_send data
 */
class TestServer
{
    private $socket;
    private $host;
    private $port;
    private $running = false;
    private $messages = [];
    private $pid;

    public function __construct(string $host = '127.0.0.1', int $port = 9002)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function start(): bool
    {
        // Fork a process to run the server
        $this->pid = pcntl_fork();
        
        if ($this->pid == -1) {
            throw new \Exception('Could not fork server process');
        } elseif ($this->pid == 0) {
            // Child process - run the server
            $this->runServer();
            exit(0);
        } else {
            // Parent process - wait a bit for server to start
            usleep(100000); // 100ms
            $this->running = true;
            return true;
        }
    }

    public function stop(): void
    {
        if ($this->running && $this->pid) {
            posix_kill($this->pid, SIGTERM);
            pcntl_waitpid($this->pid, $status);
            $this->running = false;
        }
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clearMessages(): void
    {
        $this->messages = [];
    }

    private function runServer(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            error_log("Test server: Could not create socket");
            return;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (!socket_bind($this->socket, $this->host, $this->port)) {
            error_log("Test server: Could not bind to {$this->host}:{$this->port}");
            return;
        }

        if (!socket_listen($this->socket, 5)) {
            error_log("Test server: Could not listen on socket");
            return;
        }

        // Set non-blocking mode for accept
        socket_set_nonblock($this->socket);

        while (true) {
            $client = @socket_accept($this->socket);
            if ($client !== false) {
                $this->handleClient($client);
            }
            usleep(10000); // 10ms
        }
    }

    private function handleClient($client): void
    {
        try {
            while (true) {
                // Read the 4-byte length prefix
                $lengthPrefix = socket_read($client, 4, PHP_BINARY_READ);

                if ($lengthPrefix === false || strlen($lengthPrefix) < 4) {
                    break;
                }

                // Unpack the length (network byte order)
                $unpacked = unpack('Nlen', $lengthPrefix);
                $messageLength = $unpacked['len'];

                if ($messageLength > 0) {
                    $messageData = '';
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
                        // Store message in shared file for parent process to read
                        $this->storeMessage($messageData);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Test server error: " . $e->getMessage());
        } finally {
            socket_close($client);
        }
    }

    private function storeMessage(string $data): void
    {
        $messageFile = sys_get_temp_dir() . '/var_send_test_messages.json';
        $messages = [];
        
        if (file_exists($messageFile)) {
            $content = file_get_contents($messageFile);
            if ($content) {
                $messages = json_decode($content, true) ?: [];
            }
        }
        
        $messages[] = [
            'timestamp' => microtime(true),
            'data' => $data
        ];
        
        file_put_contents($messageFile, json_encode($messages));
    }
}

/**
 * Simple test server helper that doesn't require pcntl
 */
class SimpleTestServer
{
    private $host;
    private $port;
    private $messageFile;

    public function __construct(string $host = '127.0.0.1', int $port = 9002)
    {
        $this->host = $host;
        $this->port = $port;
        $this->messageFile = sys_get_temp_dir() . '/var_send_test_messages.json';
    }

    public function start(): bool
    {
        // Clear previous messages
        $this->clearMessages();
        
        // Start server in background using PHP's built-in server functionality
        $serverScript = __DIR__ . '/simple_test_server.php';
        $this->createServerScript($serverScript);
        
        $cmd = sprintf(
            'php %s %s %d > /dev/null 2>&1 &',
            escapeshellarg($serverScript),
            escapeshellarg($this->host),
            $this->port
        );
        
        exec($cmd);
        
        // Wait for server to start
        usleep(200000); // 200ms
        
        return true;
    }

    public function stop(): void
    {
        // Kill any running test servers
        exec("pkill -f 'simple_test_server.php'");
    }

    public function getMessages(): array
    {
        if (!file_exists($this->messageFile)) {
            return [];
        }
        
        $content = file_get_contents($this->messageFile);
        if (!$content) {
            return [];
        }
        
        $messages = json_decode($content, true) ?: [];
        return $messages;
    }

    public function clearMessages(): void
    {
        if (file_exists($this->messageFile)) {
            unlink($this->messageFile);
        }
    }

    public function waitForMessages(int $expectedCount, int $timeoutMs = 5000): bool
    {
        $start = microtime(true);
        $timeoutSec = $timeoutMs / 1000;
        
        while ((microtime(true) - $start) < $timeoutSec) {
            $messages = $this->getMessages();
            if (count($messages) >= $expectedCount) {
                return true;
            }
            usleep(50000); // 50ms
        }
        
        return false;
    }

    private function createServerScript(string $path): void
    {
        $script = '<?php
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
';
        file_put_contents($path, $script);
    }
}