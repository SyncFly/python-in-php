<?php

namespace Python_In_PHP;

use Exception;
use WebSocket\Client;
use Python_In_PHP\PythonDict;
use Python_In_PHP\PythonException;

/**
 * A bridge for working with Python
 */
class PythonBridge
{
    private string $host;
    private int $port;
    private string $wsUri;
    private string $working_directory;
    private string $python_script;
    private string $python_binary;
    private bool $debug;

    private ?Client $client = null;
    private bool $isConnected = false;
    private mixed $process = null;
    private array $object_references = [];
    /** @var array<string, callable> PHP callables exposed to Python, keyed by callback id */
    private array $php_callbacks = [];
    private int $timeout;
    private array $pipes;

    /**
     * @param array{
          *     debug?: bool,
          *     timeout?: int,
          *     host?: string,
          *     port?: int,
          *     working_directory?: string,
          *     python_binary?: string
          * } $options
     */
    public function __construct(array $options = [])
    {
        $this->host = $options['host'] ?? '127.0.0.1';
        $this->port = $options['port'] ?? $this->getFreePort();
        $this->wsUri = "ws://{$this->host}:{$this->port}/";

        $this->working_directory = $options['working_directory'] ?? getcwd();
        $this->python_script = __DIR__ . '/python_server/python_worker.py';
        $this->python_binary = $options['python_binary'] ?? realpath(__DIR__ . '/../python_bin') . '/python' . (PHP_OS_FAMILY == 'Windows' ? '.exe' : '');

        $this->debug = $options['debug'] ?? false;
        $this->timeout = $options['timeout'] ?? 36000;
    }

    public function __destruct()
    {
        $this->disconnect();
        $this->stop();
    }

    /**
     * @param array{
     *     debug?: bool,
     *     timeout?: int,
     *     host?: string,
     *     port?: int,
     *     working_directory?: string,
     *     python_binary?: string
     * } $options
     */
    static function startOrGetRunning(array $options = [])
    {
        global $__python_bridge;

        if (isset($__python_bridge)) {
            return $__python_bridge;
        }

        $__python_bridge = new self($options);

        return $__python_bridge;
    }

    static function getInstance(): ?self
    {
        global $__python_bridge;

        if (isset($__python_bridge)) {
            return $__python_bridge;
        }

        return null;
    }

    function isStarted(): bool
    {
        // Suppress fsockopen "Connection refused" warnings without relying on @,
        // which Pest/Collision still surfaces via its PhpWarningTriggered subscriber.
        set_error_handler(static fn() => true);
        try {
            $fp = fsockopen($this->host, $this->port, $errno, $errstr, 0.005);
        } finally {
            restore_error_handler();
        }
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    function isRunning(): bool
    {
        return $this->ping() == 'pong';
    }

    private function log($message): void
    {
        if ($this->debug) {
            echo "[DEBUG] " . date('Y-m-d H:i:s') . " - $message\n";
        }
    }

    private function startServer(): void
    {
        if ($this->isStarted()) {
            $this->log("The server is already running on the port {$this->port}");
            return;
        }

        $scriptPath = realpath($this->python_script);
        if (!$scriptPath) {
            throw new Exception("⚠️ Python script was not found: {$this->python_script}");
        }

        // Build the command as an argument array (not a string). A string command
        // makes proc_open spawn it through a shell (`/bin/sh -c` / `cmd /C`), so the
        // handle in $this->process wraps the *shell*, not Python: proc_get_status()
        // then reports the shell's PID, and stop()'s posix_kill()/taskkill by that PID
        // never reaches the Python grandchild — it survives as an orphan and does not
        // die with the launching PHP process. The array form runs Python directly, so
        // $this->process is the Python process itself and stop() can actually kill it.
        // We pass the working directory via proc_open's $cwd argument instead of a
        // shell `cd`.
        $args = [$this->python_binary, $scriptPath, '--host', $this->host, '--port', (string) $this->port];
        if ($this->debug) {
            $args[] = '--verbose';
            $args[] = '1';
        }

        $this->log("Starting the Python server: " . implode(' ', $args));

        // Detach the worker's stdio from our own descriptors. With an empty
        // descriptorspec proc_open lets the child inherit our stdin/stdout/stderr;
        // because the worker is a long-lived daemon it would then hold those fds open
        // forever. That is fatal when a parent reads our stdout to EOF (e.g. the test
        // bootstrap runs `composer install` via passthru()): composer finishes but the
        // inherited pipe never closes, so passthru() — and the whole test run — hangs.
        // Routing the child's stdio to the null device breaks that inheritance.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = $this->debug
            ? [] // keep inheriting so the worker's logs are visible while debugging
            : [
                0 => ['file', $null, 'r'],
                1 => ['file', $null, 'w'],
                2 => ['file', $null, 'w'],
            ];

        $this->process = $process = proc_open($args, $descriptors, $pipes, $this->working_directory);
        if (!is_resource($this->process)) {
            throw new Exception("❌ Failed to start the Python server process");
        }

        $this->pipes = $pipes;

        register_shutdown_function(function() {
            $this->disconnect();
            $this->stop();
        });

        // Wait for the server to start, with a timeout
        $startupTimeout = 30; // seconds
        $deadline = microtime(true) + $startupTimeout;

        while (!$this->isStarted()) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                throw new Exception("❌ The Python process exited unexpectedly before the server started");
            }

            if (microtime(true) >= $deadline) {
                $this->stop();
                throw new Exception("❌ The Python server did not start within {$startupTimeout} seconds");
            }

            usleep(50000); // 50ms
        }

        $this->log("✅ The Python server was started successfully");
    }

    /**
     * PID of the spawned Python worker process, or null if no worker is running.
     * Because the worker is now launched without a shell wrapper, this is the PID of
     * the Python process itself — the one stop() signals on shutdown.
     */
    public function getWorkerPid(): ?int
    {
        if (!is_resource($this->process)) {
            return null;
        }
        $status = proc_get_status($this->process);
        return $status['running'] ? $status['pid'] : null;
    }

    private function getFreePort(): int {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0); // 0 = OS will choose a free port
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);
        return $port;
    }

    private function connectToServer(): void
    {
        if ($this->isConnected) {
            return;
        }

        if (!$this->isStarted()) {
            $this->startServer();
        }

        $this->log("Establishing a WebSocket connection to {$this->wsUri}");

        try {
            $this->client = new Client($this->wsUri, [
                'timeout' => $this->timeout,
            ]);
            $this->isConnected = true;
        }
        catch (Exception $e) {
            throw new Exception("❌ Unable to connect to the WebSocket server: " . $e->getMessage());
        }

        $this->log("✅ The connection established");
    }

    private function stop()
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        $pid = $status['pid'];

        // Close all pipes first, otherwise proc_close may hang
        foreach ($this->pipes ?? [] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if ($status['running']) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$pid}");
            }
            else {
                // $pid is the Python process itself (spawned via the array form of
                // proc_open, without a shell wrapper), so signalling it directly is
                // enough — no process group is involved.
                posix_kill($pid, SIGTERM);

                $deadline = microtime(true) + 3;
                while (microtime(true) < $deadline) {
                    usleep(100000); // 100ms
                    $check = proc_get_status($this->process);
                    if (!$check['running']) {
                        break;
                    }
                }

                // If still alive — SIGKILL
                $check = proc_get_status($this->process);
                if ($check['running']) {
                    posix_kill($pid, SIGKILL);
                }
            }
        }

        proc_close($this->process);
        $this->process = null;
    }

    private function processResult($result)
    {
        if (is_array($result) && isset($result['__python_ref__'])) {
            $obj = new PythonObject($this, $result);
            $this->object_references[$result['obj_id']] = $obj;
            return $obj;
        }
        elseif (is_array($result) && isset($result['__python_type__'])) {
            if ($result['__python_type__'] === 'dict') {
                $processedData = [];
                foreach ($result['value'] as $key => $value) {
                    $processedData[$key] = $this->processResult($value);
                }
                return new PythonDict($processedData);
            }
        }
        elseif (is_array($result) && isset($result['__python_float__'])) {
            return match($result['__python_float__']) {
                'NAN'  => NAN,
                'INF'  => INF,
                '-INF' => -INF,
                default => $result,
            };
        }
        elseif (is_array($result)) {
            $processedArray = [];
            foreach ($result as $key => $value) {
                $processedArray[$key] = $this->processResult($value);
            }
            return $processedArray;
        }

        return $result;
    }

    /** @throws \BadMethodCallException always — async is not yet implemented */
    public function async(callable $operation): never
    {
        throw new \BadMethodCallException('Async operations are not yet implemented');
    }

    /** @throws \BadMethodCallException always — async is not yet implemented */
    public function await($operationId, $timeout = null): never
    {
        throw new \BadMethodCallException('Async operations are not yet implemented');
    }

    /** @throws \BadMethodCallException always — async is not yet implemented */
    public function asyncCall($function, $args = [], $kwargs = []): never
    {
        throw new \BadMethodCallException('Async operations are not yet implemented');
    }

    /** @throws \BadMethodCallException always — async is not yet implemented */
    public function asyncCallMethod($objId, $method, $args = [], $kwargs = []): never
    {
        throw new \BadMethodCallException('Async operations are not yet implemented');
    }

    /**
     * Working with Python's context manager
     */
    public function with($objId, callable $callback)
    {
        try {
            // Enter context
            $this->execute('context_enter', ['obj_id' => $objId]);

            // Callback execution
            $result = $callback();

            // Exit the context
            $this->execute('context_exit', ['obj_id' => $objId]);

            return $result;
        }
        catch (Exception $e) {
            // In case of an error, we also exit the context
            try {
                $this->execute('context_exit', ['obj_id' => $objId]);
            }
            catch (Exception $exitError) {
                $this->log("Error during context exit: " . $exitError->getMessage());
            }
            throw $e;
        }
    }

    /** @throws \BadMethodCallException always — async is not yet implemented */
    public function asyncWith($objId, callable $callback): never
    {
        throw new \BadMethodCallException('Async operations are not yet implemented');
    }

    /**
     * Execute a command on the Python server.
     *
     * @throws PythonException   when Python raises an exception
     * @throws \RuntimeException when the connection is lost and cannot be re-established
     */
    public function execute(string $command, array $args = [], ?string $module = null): mixed
    {
        if (!$this->isConnected) {
            $this->connectToServer();
        }

        $payload = json_encode([
            'command' => $command,
            'args' => $args,
            'module' => $module,
            'id' => uniqid()
        ]);

        $this->log("Sending command: $payload");

        try {
            $this->client->send($payload);
            $response = $this->client->receive();
        } catch (\Exception $e) {
            // Connection dropped — try once to reconnect and replay the request.
            // Only the initial send/receive is retried; a drop mid callback exchange propagates.
            $this->log("Connection lost ({$e->getMessage()}), attempting reconnect…");
            $this->isConnected = false;
            $this->client = null;
            try {
                $this->connectToServer();
                $this->client->send($payload);
                $response = $this->client->receive();
            } catch (\Exception $reconnectEx) {
                throw new \RuntimeException(
                    "Python server connection lost and reconnect failed: " . $reconnectEx->getMessage(),
                    0,
                    $reconnectEx
                );
            }
        }

        // Re-entrant loop: the worker may interleave callback invoke/release frames
        // before the actual response — service them until the response arrives.
        while (true) {
            $this->log("Frame received: " . substr($response, 0, 2000));

            $frame = json_decode($response, true, 10000);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("JSON parsing error: " . json_last_error_msg());
            }

            $frameCommand = $frame['command'] ?? null;

            if ($frameCommand === 'invoke_php_callback') {
                $this->runPhpCallback($frame);
                $response = $this->client->receive();
                continue;
            }

            if ($frameCommand === 'release_php_callback') {
                unset($this->php_callbacks[$frame['callback_id'] ?? '']);
                $response = $this->client->receive();
                continue;
            }

            // Terminal response for our command.
            if (isset($frame['error']) && $frame['error']) {
                $traceback = $frame['traceback'] ?? '';
                $message = "Python error: " . $frame['error'];
                if ($traceback) {
                    $message .= "\n\nTraceback:\n" . $traceback;
                }
                throw new PythonException($message, traceback: $traceback);
            }

            // Process the result to create object references
            return $this->processResult($frame['result'] ?? null);
        }
    }

    /**
     * Run the PHP callable named by an "invoke_php_callback" frame and send its
     * result (or error) back. The callable may re-enter Python via a nested Py:: call.
     */
    private function runPhpCallback(array $frame): void
    {
        $callbackId = $frame['callback_id'] ?? null;
        $frameId = $frame['id'] ?? null;
        $this->log("Invoking PHP callback {$callbackId}");

        try {
            if ($callbackId === null || !isset($this->php_callbacks[$callbackId])) {
                throw new \RuntimeException("PHP callback {$callbackId} is not registered");
            }
            $callable = $this->php_callbacks[$callbackId];

            // Resolve incoming arguments (Python object refs -> PythonObject, etc.)
            $args = $this->processResult($frame['args'] ?? []);
            $kwargs = $this->processResult($frame['kwargs'] ?? []);
            $args = is_array($args) ? array_values($args) : [];
            $kwargs = is_array($kwargs) ? $kwargs : [];

            // String-keyed kwargs are spread as PHP named arguments (PHP 8.1+).
            $ret = $callable(...$args, ...$kwargs);

            $reply = json_encode([
                'callback_result' => $this->serializeArg($ret),
                'error' => null,
                'id' => $frameId,
            ]);
        } catch (\Throwable $e) {
            $reply = json_encode([
                'callback_result' => null,
                'error' => $e->getMessage(),
                'traceback' => $e->getTraceAsString(),
                'id' => $frameId,
            ]);
        }

        $this->client->send($reply);
    }

    /**
     * Register a PHP callable so Python can invoke it, and return its id.
     */
    private function registerCallback(callable $callable): string
    {
        $id = bin2hex(random_bytes(8));
        $this->php_callbacks[$id] = $callable;
        return $id;
    }

    /**
     * Serialize a single argument for the wire. PHP callables become a
     * __php_callable__ marker Python turns into a callable proxy; arrays are
     * walked so nested callables are caught too.
     */
    private function serializeArg(mixed $arg): mixed
    {
        if ($arg instanceof PythonObject) {
            return $arg->toArray();
        }

        if ($arg instanceof PhpCallback) {
            return ['__php_callable__' => true, 'callback_id' => $this->registerCallback($arg->getCallable())];
        }

        // Bare strings are excluded: a function-name string is indistinguishable
        // from data, so it stays data unless wrapped in Py::callback().
        if (!is_string($arg) && is_callable($arg)) {
            return ['__php_callable__' => true, 'callback_id' => $this->registerCallback($arg)];
        }

        if (is_array($arg)) {
            return array_map($this->serializeArg(...), $arg);
        }

        return $arg;
    }

    private function processArguments(array $args): array
    {
        return array_map($this->serializeArg(...), $args);
    }

    private function processKwargs(array $kwargs): array
    {
        return array_map($this->serializeArg(...), $kwargs);
    }

    public function call($function, $args = [], $kwargs = [])
    {
        return $this->execute('call', [
            'function' => $function,
            'args' => $this->processArguments($args),
            'kwargs' => $this->processKwargs($kwargs)
        ]);
    }

    public function eval($code)
    {
        return $this->execute('eval', ['code' => $code]);
    }

    public function exec($code)
    {
        return $this->execute('exec', ['code' => $code]);
    }

    public function importModule($moduleName, $alias = null)
    {
        return $this->execute('import', ['module' => $moduleName, 'alias' => $alias]);
    }

    /**
     * Call a method on a referenced object
     */
    public function callMethod($objId, $method, $args = [], $kwargs = [])
    {
        return $this->execute('call_method', [
            'obj_id' => $objId,
            'method' => $method,
            'args' => $this->processArguments($args),
            'kwargs' => $this->processKwargs($kwargs)
        ]);
    }

    /**
     * Call an object as a function
     */
    public function callObject($objId, $args = [], $kwargs = [])
    {
        return $this->execute('call_object', [
            'obj_id' => $objId,
            'args' => $this->processArguments($args),
            'kwargs' => $this->processKwargs($kwargs)
        ]);
    }

    /**
     * Get an attribute of an object
     */
    public function getAttribute($objId, $attribute)
    {
        return $this->execute('get_attribute', [
            'obj_id' => $objId,
            'attribute' => $attribute
        ]);
    }

    /**
     * Release an object from Python memory
     */
    public function releaseObject($objId)
    {
        try {
            $result = $this->execute('release_object', ['obj_id' => $objId]);
            unset($this->object_references[$objId]);
            return $result;
        } catch (Exception $e) {
            $this->log("Error releasing object $objId: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convert an object to a string
     */
    public function objectToString($objId): string
    {
        return $this->execute('to_string', ['obj_id' => $objId]);
    }

    /**
     * Get the list of all objects in Python memory
     */
    public function listObjects(): array
    {
        return $this->execute('list_objects');
    }

    /**
     * Ping the server
     */
    public function ping(): string
    {
        return $this->execute('ping');
    }

    /**
     * Get the list of loaded modules
     */
    public function listModules(): array
    {
        return $this->execute('list_modules');
    }

    /**
     * Check whether an object is a generator
     */
    public function isGenerator($objId): bool
    {
        return $this->execute('is_generator', ['obj_id' => $objId]);
    }

    public function getModuleNamesInPackages(array $packages)
    {
        return $this->execute('get_module_names_in_packages', ['packages' => $packages]);
    }

    public function inspectModules(array $modules)
    {
        return $this->execute('inspect_modules', ['modules' => $modules]);
    }

    public function getMethodsAndProperties($objId)
    {
        return $this->execute('get_methods_and_properties', ['obj_id' => $objId]);
    }

    private function disconnect()
    {
        if ($this->client) {
            // Only perform the WebSocket closing handshake while the worker is still
            // alive. close() reads/writes the socket to exchange close frames; against
            // a worker that has already stopped/crashed that just emits warnings (and
            // then throws) for nothing — we're discarding the connection anyway. The
            // try/catch stays as a safety net for the worker dying mid-handshake.
            if ($this->getWorkerPid() !== null) {
                try {
                    $this->client->close();
                } catch (\Throwable $e) {
                    $this->log("Ignoring error while closing WebSocket connection: {$e->getMessage()}");
                }
            }
            $this->client = null;
            $this->isConnected = false;
            $this->log("WebSocket connection closed");
        }
    }
}