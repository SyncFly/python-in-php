<?php

use Python_In_PHP\PythonBridge;

class Py
{
    static function instance(): ?PythonBridge
    {
        return PythonBridge::getInstance();
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