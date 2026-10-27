<?php

use Python_In_PHP\PythonBridge;
use Python_In_PHP\PythonException;

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

// Python errors keep their type (and traceback) whichever PythonObject path raised them.

test('a failing method call throws PythonException with a traceback', function () {
    $json = Py::import('json');

    try {
        $json->loads('not json');
        $this->fail('Expected PythonException');
    } catch (PythonException $e) {
        expect($e->getMessage())->toContain('Expecting value')
            ->and($e->traceback)->toContain('json/decoder.py');
    }

    // A missing method is reported by the worker without a traceback, but still typed
    expect(fn () => $json->no_such_method())->toThrow(PythonException::class, 'no_such_method');
});

test('a missing attribute throws PythonException, isset() reports false', function () {
    $json = Py::import('json');

    expect(fn () => $json->no_such_attr)->toThrow(PythonException::class, 'no_such_attr')
        ->and(isset($json->no_such_attr))->toBeFalse()
        ->and(isset($json->dumps))->toBeTrue();
});

test('a bad index and bad method arguments throw PythonException', function () {
    $range = Py::eval('range(3)'); // stays a PythonObject, unlike a dict/list

    expect(fn () => $range[10])->toThrow(PythonException::class, 'out of range')
        ->and(isset($range[10]))->toBeFalse()
        ->and(isset($range[1]))->toBeTrue()
        ->and(fn () => $range->index('x'))->toThrow(PythonException::class, 'not in sequence');
});

// The worker's import root is the working directory, so project modules are importable.

test('modules in working_directory are importable', function () {
    $dir = sys_get_temp_dir() . '/python-in-php-syspath-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/pip_probe_mod.py", "VALUE = 42\n");

    $bridge = new PythonBridge(['working_directory' => $dir]);
    try {
        expect($bridge->importModule('pip_probe_mod')->VALUE)->toBe(42);
    } finally {
        $bridge->stop();
        unlink("$dir/pip_probe_mod.py");
        array_map('unlink', glob("$dir/__pycache__/*") ?: []);
        @rmdir("$dir/__pycache__");
        rmdir($dir);
    }
});
