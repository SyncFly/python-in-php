<?php

use Python_In_PHP\PythonObject;

// Py static helpers for the Python core: exec/eval and builtins PHP lacks or handles differently.

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

test('eval returns the value of a Python expression', function () {
    expect(Py::eval('2 ** 10'))->toBe(1024);
    expect(Py::eval('sum([1, 2, 3])'))->toBe(6);
});

test('exec runs statements and returns null', function () {
    expect(Py::exec('x = 1 + 1'))->toBeNull();
});

test('sum aggregates any iterable, with an optional start', function () {
    expect(Py::sum([1, 2, 3]))->toBe(6);
    expect(Py::sum([1, 2, 3], 10))->toBe(16);
});

test('len, min and max work on iterables', function () {
    expect(Py::len([1, 2, 3, 4]))->toBe(4);
    expect(Py::min([3, 1, 2]))->toBe(1);
    expect(Py::max([3, 1, 2]))->toBe(3);
});

test('sorted accepts a Python keyword argument via a PHP named argument', function () {
    expect(Py::sorted([3, 1, 2]))->toBe([1, 2, 3]);
    expect(Py::sorted([3, 1, 2], reverse: true))->toBe([3, 2, 1]);
});

test('range returns a Python object that list() materialises', function () {
    $range = Py::range(5);
    expect($range)->toBeInstanceOf(PythonObject::class);
    expect(Py::list($range))->toBe([0, 1, 2, 3, 4]);
});

test('builtin calls an arbitrary Python builtin by name', function () {
    expect(Py::builtin('abs', -7))->toBe(7);
    expect(Py::builtin('pow', 2, 8))->toBe(256);
    expect(Py::builtin('sorted', [3, 1, 2], reverse: true))->toBe([3, 2, 1]);
});

test('type conversions round-trip through Python builtins', function () {
    expect(Py::int('42'))->toBe(42);
    expect(Py::float('1.5'))->toBe(1.5);
    expect(Py::str(42))->toBe('42');
    expect(Py::list('ab'))->toBe(['a', 'b']);
});
