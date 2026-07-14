<?php

/**
 * Helper started as a *separate* PHP process by WorkerLifecycleTest.
 *
 * It spawns a Python worker and then simply returns. There is deliberately no
 * explicit shutdown call: ending the script must be enough for the worker to be
 * terminated (via the shutdown handler registered in PythonBridge::startServer()).
 * The parent test asserts the reported PID is gone once this process has exited.
 *
 * Usage: php spawn_worker.php /path/to/composer/autoload.php
 */

require $argv[1];

$bridge = new Python_In_PHP\PythonBridge();
$bridge->ping();                  // forces the worker to spawn and connect

echo $bridge->getWorkerPid();     // report the worker PID to the parent process
