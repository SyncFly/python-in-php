<?php

use py\builtins;
use Python_In_PHP\PythonObject;
use Python_In_PHP\PythonException;

// Py::callback lives in the root Py.php, which is not covered by the PSR-4
// autoloader — load it explicitly for the wrapper-based assertions.
require_once dirname(__DIR__, 2) . '/Py.php';

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

test('a PHP closure is invoked by Python via map()', function () {
    $doubled = builtins::list(builtins::map(fn ($x) => $x * 2, [1, 2, 3]));

    expect($doubled)->toBe([2, 4, 6]);
});

test('a PHP callback works as a sorted() key', function () {
    $sorted = builtins::sorted(['ccc', 'a', 'bb'], key: fn ($w) => strlen($w));

    expect($sorted)->toBe(['a', 'bb', 'ccc']);
});

test('an exception thrown in a PHP callback propagates to the caller', function () {
    // PythonObject::__call re-wraps the underlying PythonException as a base
    // Exception, so we assert on the message the callback failure carries.
    expect(fn () => builtins::list(builtins::map(
        fn ($x) => throw new RuntimeException('boom from php'),
        [1, 2, 3]
    )))->toThrow(Exception::class, 'boom from php');
});

test('a PHP callback may re-enter Python (nested call)', function () {
    // The closure calls back into Python (builtins.max) while Python is calling it.
    $clamped = builtins::list(builtins::map(
        fn ($x) => builtins::max([$x, 10]),
        [1, 20, 3]
    ));

    expect($clamped)->toBe([10, 20, 10]);
});

test('a PHP callback persists inside a Python object across calls', function () {
    // functools.partial stores the callback inside a Python object that outlives
    // the call which created it; invoking the partial later calls back into PHP.
    $adder = \py\functools::partial(Py::callback(fn ($a, $b) => $a + $b), 10);

    expect($adder)->toBeInstanceOf(PythonObject::class)
        ->and($adder(5))->toBe(15)
        ->and($adder(32))->toBe(42);
});
