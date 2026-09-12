<?php

use Python_In_PHP\PythonBridge;
use Python_In_PHP\WorkerCrashedException;

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

afterEach(function () {
    foreach (PythonBridge::ENV_OPTIONS as $env) {
        putenv($env);
    }
});

function tempLogPath(): string
{
    return sys_get_temp_dir() . '/python-in-php-test-' . bin2hex(random_bytes(4)) . '.log';
}

/** Reads a private/protected property of the bridge. */
function bridgeOption(PythonBridge $bridge, string $name): mixed
{
    return (new ReflectionProperty(PythonBridge::class, $name))->getValue($bridge);
}

// ----- options & environment -----

test('options fall back to PYTHON_IN_PHP_* environment variables', function () {
    putenv('PYTHON_IN_PHP_TIMEOUT=42');
    putenv('PYTHON_IN_PHP_DEBUG=false');
    putenv('PYTHON_IN_PHP_LOG_FILE=/tmp/some.log');
    putenv('PYTHON_IN_PHP_HOST=127.0.0.1');

    $bridge = new PythonBridge();

    expect(bridgeOption($bridge, 'timeout'))->toBe(42)
        ->and(bridgeOption($bridge, 'debug'))->toBeFalse()
        ->and(bridgeOption($bridge, 'log_file'))->toBe('/tmp/some.log')
        ->and(bridgeOption($bridge, 'host'))->toBe('127.0.0.1');
});

test('explicit options win over environment variables', function () {
    putenv('PYTHON_IN_PHP_TIMEOUT=42');

    $bridge = new PythonBridge(['timeout' => 7]);

    expect(bridgeOption($bridge, 'timeout'))->toBe(7);
});

test('startOrGetRunning() rejects conflicting options instead of ignoring them', function () {
    $first = PythonBridge::startOrGetRunning(['timeout' => 100]);

    // The same options (or none) are fine…
    expect(PythonBridge::startOrGetRunning(['timeout' => 100]))->toBe($first)
        ->and(PythonBridge::startOrGetRunning())->toBe($first);

    // …but different ones must not be silently dropped.
    expect(fn () => PythonBridge::startOrGetRunning(['timeout' => 5]))
        ->toThrow(LogicException::class, 'already running with different options');
});

// ----- logging -----

test('log_file captures the worker stdout and stderr', function () {
    $log = tempLogPath();
    $bridge = new PythonBridge(['log_file' => $log]);

    $bridge->exec('import sys, warnings; sys.stderr.write("to-stderr\n"); print("to-stdout"); warnings.warn("careful")');
    $bridge->stop();

    $content = file_get_contents($log);
    unlink($log);

    expect($bridge->getLogFile())->toBe($log)
        ->and($content)->toContain('to-stderr')
        ->and($content)->toContain('to-stdout')
        ->and($content)->toContain('UserWarning: careful');
});

test('a PSR-3 logger receives the worker output with a guessed level', function () {
    $logger = new class extends \Psr\Log\AbstractLogger {
        public array $records = [];
        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message];
        }
    };

    $bridge = new PythonBridge(['logger' => $logger]);
    $bridge->exec('import sys; sys.stderr.write("[LightGBM] [Warning] boom\n"); print("plain line")');
    $tempLog = $bridge->getLogFile();
    $bridge->stop();

    $python = array_values(array_filter($logger->records, fn ($r) => str_starts_with($r[1], '[python] ')));

    expect($python)->toContain(['warning', '[python] [LightGBM] [Warning] boom'])
        ->and($python)->toContain(['info', '[python] plain line'])
        ->and($tempLog)->not->toBeNull()
        ->and(file_exists($tempLog))->toBeFalse(); // the temp log is removed on stop()
});

// ----- worker crashes & lifecycle -----

test('a worker that dies mid-call throws WorkerCrashedException with the log tail', function () {
    $log = tempLogPath();
    $bridge = new PythonBridge(['log_file' => $log]);
    $bridge->exec('import sys; sys.stderr.write("last words\n")');

    try {
        $bridge->exec('import os; os._exit(3)');
        $this->fail('Expected WorkerCrashedException');
    } catch (WorkerCrashedException $e) {
        expect($e->command)->toBe('exec')
            ->and($e->exitCode)->toBe(3)
            ->and($e->signal)->toBeNull()
            ->and($e->logFile)->toBe($log)
            ->and($e->logTail)->toContain('last words')
            ->and($e->getMessage())->toContain('exit code 3')
            ->and($e->getMessage())->toContain('last words');
    }

    expect($bridge->isRunning())->toBeFalse()
        ->and($bridge->getWorkerPid())->toBeNull();

    // The next call transparently starts a fresh worker.
    expect($bridge->ping())->toBe('pong');

    $bridge->stop();
    unlink($log);
});

test('without a log file the crash message says how to enable one', function () {
    $bridge = new PythonBridge();

    expect(fn () => $bridge->exec('import os; os._exit(1)'))
        ->toThrow(WorkerCrashedException::class, 'PYTHON_IN_PHP_LOG_FILE');
});

test('a worker killed by a signal is reported with the signal number', function () {
    if (!function_exists('posix_kill')) {
        $this->markTestSkipped('ext-posix is required');
    }
    $bridge = new PythonBridge();
    $bridge->ping();

    posix_kill($bridge->getWorkerPid(), SIGKILL);

    try {
        $bridge->ping();
        $this->fail('Expected WorkerCrashedException');
    } catch (WorkerCrashedException $e) {
        expect($e->signal)->toBe(SIGKILL)
            ->and($e->getMessage())->toContain('killed by signal ' . SIGKILL);
    }
});

test('objects from a crashed worker are released without restarting it', function () {
    $bridge = new PythonBridge();
    $object = $bridge->eval('object()');

    expect(fn () => $bridge->exec('import os; os._exit(1)'))->toThrow(WorkerCrashedException::class);

    unset($object); // destructor → releaseObject() on a stale id

    expect($bridge->getWorkerPid())->toBeNull()
        ->and($bridge->isRunning())->toBeFalse();
});

test('stop() and restart() are public and idempotent', function () {
    $bridge = new PythonBridge();
    $bridge->ping();
    $pid = $bridge->getWorkerPid();

    $bridge->stop();
    $bridge->stop();
    expect($bridge->isRunning())->toBeFalse()
        ->and($bridge->getWorkerPid())->toBeNull();

    $bridge->restart();
    expect($bridge->isRunning())->toBeTrue()
        ->and($bridge->getWorkerPid())->not->toBe($pid);

    $bridge->stop();
});

test('isRunning() never starts a worker', function () {
    $bridge = new PythonBridge();

    expect($bridge->isRunning())->toBeFalse()
        ->and($bridge->getWorkerPid())->toBeNull();
});

test('Py::stop(), Py::restart() and Py::isRunning() work statically', function () {
    Py::startIfNotStarted();
    expect(Py::isRunning())->toBeTrue();

    Py::stop();
    expect(Py::isRunning())->toBeFalse();

    expect(Py::len([1, 2, 3]))->toBe(3); // auto-restarts
    Py::restart();
    expect(Py::eval('1 + 1'))->toBe(2);
});

// ----- installation check -----

test('isInstalled() reports the venv without spawning anything', function () {
    $bridge = new PythonBridge();

    expect(Py::isInstalled())->toBeTrue()
        ->and(PythonBridge::pythonBinary())->toEndWith('python')
        ->and($bridge->getWorkerPid())->toBeNull();
});

test('isInstalled() is false when PYTHON_IN_PHP_PYTHON_BINARY points at a missing file', function () {
    putenv('PYTHON_IN_PHP_PYTHON_BINARY=/definitely/missing/python');

    expect(PythonBridge::isInstalled())->toBeFalse()
        ->and(PythonBridge::pythonBinary())->toBe('/definitely/missing/python');
});

test('a missing interpreter fails fast with a clear message', function () {
    $bridge = new PythonBridge(['python_binary' => '/definitely/missing/python']);

    expect(fn () => $bridge->ping())
        ->toThrow(RuntimeException::class, 'Python environment is not installed');
});

// ----- large payloads (above the websockets 1 MiB default) -----

test('multi-megabyte arguments and results cross the bridge', function () {
    $bridge = new PythonBridge();
    $data = range(1, 300_000); // ~2 MB of JSON

    expect($bridge->call('sum', [$data]))->toBe(array_sum($data));

    $back = $bridge->eval('list(range(300000))');
    expect($back)->toHaveCount(300_000)
        ->and($back[299_999])->toBe(299_999);

    $bridge->stop();
});

// ----- collections are inlined regardless of size -----

test('large and nested collections are returned as PHP arrays, not references', function () {
    $bridge = new PythonBridge();

    $large = $bridge->eval('list(range(1000))');
    $nested = $bridge->eval('[[1, 2], {"a": (3, 4)}]');
    $dict = $bridge->eval('{str(i): i for i in range(500)}');

    expect($large)->toBeArray()->toHaveCount(1000)
        ->and($large[999])->toBe(999)
        ->and($nested)->toBe([[1, 2], ['a' => [3, 4]]])
        ->and($dict)->toBeArray()->toHaveCount(500);

    $bridge->stop();
});
