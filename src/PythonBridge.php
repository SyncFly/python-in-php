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
    private mixed $process;
    private array $object_references = [];
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

        $verbose = $this->debug ? '--verbose 1' : '';

        $cmd = "{$this->python_binary} \"$scriptPath\" --host {$this->host} --port {$this->port} $verbose";

        $this->log("Starting the Python server: $cmd");

        if (PHP_OS_FAMILY === 'Windows') {
            $redirect = $this->debug ? '' : '>nul 2>&1';
            $command = 'cmd /C "cd /D "' . $this->working_directory . '" && ' . $cmd . ' ' . $redirect . '"';
            $this->process = $process = proc_open($command, [], $pipes);
        }
        else {
            $redirect = $this->debug ? '' : '> /dev/null 2>&1';
            $command = "cd \"{$this->working_directory}\" && {$cmd} $redirect";
            $this->process = $process = proc_open($command, [], $pipes);
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
                // Send SIGTERM to the shell process by its exact PID.
                // Using -$pid (process group) would fail because proc_open does
                // not create a new process group, so the PGID never equals $pid.
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

        $this->log("Response received: " . substr($response, 0, 2000));

        $result = json_decode($response, true, 10000);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("JSON parsing error: " . json_last_error_msg());
        }

        if (isset($result['error']) && $result['error']) {
            $traceback = $result['traceback'] ?? '';
            $message = "Python error: " . $result['error'];
            if ($traceback) {
                $message .= "\n\nTraceback:\n" . $traceback;
            }
            throw new PythonException($message, traceback: $traceback);
        }

        // Process the result to create object references
        $processedResult = $this->processResult($result['result'] ?? null);

        return $processedResult;
    }

    private function serializeArg(mixed $arg): mixed
    {
        return $arg instanceof PythonObject ? $arg->toArray() : $arg;
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
            $this->client->close();
            $this->client = null;
            $this->isConnected = false;
            $this->log("WebSocket connection closed");
        }
    }
}