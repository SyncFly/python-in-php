<?php

use py\builtins;
use Python_In_PHP\PythonClass;

// Generated stub classes: PHP-keyword names are callable as-is; sanitized class names map back to Python.

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

test('Python functions whose names are PHP keywords are called without an underscore', function () {
    expect(builtins::list(Py::range(3)))->toBe([0, 1, 2]);
    expect(builtins::abs(-5))->toBe(5);
});

test('underscore-prefixed class stubs resolve to the unprefixed Python class', function () {
    $obj = new \py\builtins\_object();
    expect($obj)->toBeInstanceOf(PythonClass::class);
    expect((string) $obj)->toContain('object');
});

test('exception class stubs construct real Python objects', function () {
    $exc = new \py\builtins\ValueError('boom');
    expect((string) $exc)->toBe('boom');
});

test('value-type constructors hold the converted native value and behave like it', function () {
    $l = new \py\builtins\_list([1, 2, 3]);
    expect($l->toArray())->toBe([1, 2, 3]);
    expect(count($l))->toBe(3);
    expect($l[0])->toBe(1);
    expect(iterator_to_array($l))->toBe([1, 2, 3]);
    expect((string) new \py\builtins\str(123))->toBe('123');
});

test('Python method calls on a natively converted value fail with a clear error', function () {
    $l = new \py\builtins\_list([1, 2, 3]);
    expect(fn() => $l->append(4))->toThrow(BadMethodCallException::class, 'native array');
});
