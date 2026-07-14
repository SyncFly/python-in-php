<?php

use Python_In_PHP\PythonBridge;

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
    // On POSIX we probe processes with posix_kill(); on Windows we shell out to
    // tasklist instead (see processIsAlive()), so ext-posix is only required there.
    if (PHP_OS_FAMILY !== 'Windows' && !function_exists('posix_kill')) {
        $this->markTestSkipped('ext-posix is required for these process-lifecycle tests');
    }
});

/** Whether a process with the given PID currently exists — cross-platform. */
function processIsAlive(int $pid): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('tasklist /FI ' . escapeshellarg("PID eq {$pid}") . ' /FO CSV /NH 2>NUL');
        // A matching process yields a CSV row containing the quoted PID; no match
        // yields an "INFO: No tasks…" line that never contains "<pid>".
        return is_string($out) && str_contains($out, '"' . $pid . '"');
    }

    return posix_kill($pid, 0);
}

/** The image/command name of a process, or null if it isn't running — cross-platform. */
function processImage(int $pid): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('tasklist /FI ' . escapeshellarg("PID eq {$pid}") . ' /FO CSV /NH 2>NUL');
        if (is_string($out) && str_contains($out, '"' . $pid . '"') && preg_match('/^"([^"]+)"/m', $out, $m)) {
            return $m[1]; // e.g. "python.exe"
        }
        return null;
    }

    // Linux and macOS both provide `ps`.
    $out = shell_exec('ps -p ' . (int) $pid . ' -o comm= 2>/dev/null');
    $out = is_string($out) ? trim($out) : '';
    return $out !== '' ? $out : null;
}

/** Poll until the process is gone (or the timeout elapses). */
function waitUntilProcessGone(int $pid, float $timeout = 8.0): bool
{
    $deadline = microtime(true) + $timeout;
    do {
        if (!processIsAlive($pid)) {
            return true;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    return !processIsAlive($pid);
}

test('stop() terminates the worker, whose handle targets Python directly', function () {
    $bridge = new PythonBridge();
    $bridge->ping(); // forces the worker to spawn

    $pid = $bridge->getWorkerPid();

    expect($pid)->toBeInt()->toBeGreaterThan(0)
        ->and(processIsAlive($pid))->toBeTrue()   // the worker is alive
        ->and($bridge->isStarted())->toBeTrue();  // the server is listening

    // Regression guard: launching Python through a string command would wrap it in
    // `sh -c` / `cmd /C`, so this PID would be the shell and stop()'s kill-by-PID would
    // orphan Python. With the array-form proc_open the PID must be Python itself.
    $image = processImage($pid);
    if ($image !== null) {
        expect(strtolower($image))->toContain('python');
    }

    // stop() must actually terminate the worker. The worker ignores SIGTERM, so this
    // exercises the SIGTERM→SIGKILL escalation (taskkill /F on Windows) and the
    // proc_close() that reaps it — all keyed off the worker's real PID.
    $stop = new ReflectionMethod($bridge, 'stop');
    $stop->setAccessible(true);
    $stop->invoke($bridge);

    expect(waitUntilProcessGone($pid))->toBeTrue()
        ->and($bridge->getWorkerPid())->toBeNull()
        ->and($bridge->isStarted())->toBeFalse();
});

test('the worker is terminated when its launching PHP process exits', function () {
    $autoload = dirname(__DIR__, 2) . '/fixtures/project/vendor/autoload.php';
    $script   = dirname(__DIR__) . '/Fixtures/spawn_worker.php';

    // Run a short-lived child PHP process that starts a worker and then just exits.
    $output = shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($autoload)
    );
    $pid = (int) trim((string) $output);

    expect($pid)->toBeGreaterThan(0); // the child reported a real worker PID

    // The child has now exited; its shutdown handler must have stopped the worker,
    // otherwise the Python process would be orphaned and keep running.
    expect(waitUntilProcessGone($pid))->toBeTrue();
});
