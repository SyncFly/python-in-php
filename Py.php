<?php

use Python_In_PHP\PythonBridge;
use Python_In_PHP\PhpCallback;

class Py
{
    static function instance(): ?PythonBridge
    {
        return PythonBridge::getInstance();
    }

    /**
     * Wrap a PHP callable so Python can invoke it. Bare callables are auto-detected;
     * use this for function-name strings or to make intent explicit.
     */
    static function callback(callable $callable): PhpCallback
    {
        return new PhpCallback($callable);
    }

    static function startIfNotStarted(array $options = []): void
    {
        PythonBridge::startOrGetRunning($options);
    }

    static function isRunning(): bool
    {
        return self::instance()?->isRunning() ?? false;
    }

    function stop(): void
    {
        self::instance()?->__destruct();
    }

    public static function __callStatic($name, $arguments)
    {
        return self::instance()?->$name(...$arguments);
    }

    function runInCloud()
    {
        //@TODO
    }

    function sharedFilesWithCloud()
    {
        //@TODO
    }
}