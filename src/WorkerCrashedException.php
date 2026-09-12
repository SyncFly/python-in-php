<?php

namespace Python_In_PHP;

/**
 * Thrown when the Python worker process dies mid-session (segfault, OOM kill,
 * sys.exit() inside library code…). Distinct from PythonException, which is a
 * Python-level error inside a worker that is still alive.
 */
class WorkerCrashedException extends \RuntimeException
{
    public function __construct(
        /** Bridge command that was in flight when the worker died */
        public readonly string $command,
        /** Exit code, or null when unknown (e.g. killed by a signal) */
        public readonly ?int $exitCode,
        /** Signal number that terminated the worker, or null */
        public readonly ?int $signal,
        /** Path of the worker log the tail was read from, or null when none was configured */
        public readonly ?string $logFile,
        /** Last lines of the worker's stdout/stderr, empty when no log file is configured */
        public readonly string $logTail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($this->buildMessage(), 0, $previous);
    }

    private function buildMessage(): string
    {
        $how = match (true) {
            $this->signal !== null => "killed by signal {$this->signal}",
            $this->exitCode !== null => "exit code {$this->exitCode}",
            default => 'unknown exit status',
        };

        $message = "Python worker exited unexpectedly ({$how}) while executing '{$this->command}'.";

        if ($this->logFile === null) {
            return $message . " No worker log is configured — set the 'log_file' option (or PYTHON_IN_PHP_LOG_FILE) to capture Python's stderr.";
        }

        if ($this->logTail === '') {
            return $message . " The worker log ({$this->logFile}) is empty.";
        }

        return $message . "\n\nLast lines of the worker log ({$this->logFile}):\n" . $this->logTail;
    }
}
