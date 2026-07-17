<?php

use py\builtins;

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

test('Py::with() enters and exits a context manager (with file:)', function () {
    $path = tempnam(sys_get_temp_dir(), 'pyphp_ctx_');

    $file = builtins::open($path, 'w');
    Py::with($file, function () use ($file) {
        $file->write('hello');
    });

    expect((bool) $file->closed)->toBeTrue()
        ->and(file_get_contents($path))->toBe('hello');

    unlink($path);
});

test('Py::with() passes the entered value to the callback (as target) and returns its result', function () {
    $path = tempnam(sys_get_temp_dir(), 'pyphp_ctx_');
    file_put_contents($path, 'from disk');

    $content = Py::with(builtins::open($path, 'r'), fn ($f) => $f->read());

    expect($content)->toBe('from disk');

    unlink($path);
});

test('Py::with() exits the context even when the callback throws', function () {
    $path = tempnam(sys_get_temp_dir(), 'pyphp_ctx_');

    $file = builtins::open($path, 'w');
    expect(fn () => Py::with($file, function () {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect((bool) $file->closed)->toBeTrue();

    unlink($path);
});
