<?php

use Python_In_PHP\PythonBridge;
use Python_In_PHP\PhpCallback;

/**
 * Unit tests for PHP-callback serialization. These exercise the outbound
 * argument path (serializeArg/processArguments) without a running worker.
 */

function bridgeSerializeArgs(array $args): array
{
    // Fixed port avoids the socket_bind() in getFreePort(); the constructor does
    // not connect, so no worker is needed.
    $bridge = new PythonBridge(['port' => 59991]);
    $method = new ReflectionMethod($bridge, 'processArguments');
    $method->setAccessible(true);
    return $method->invoke($bridge, $args);
}

test('a Closure argument becomes a __php_callable__ marker', function () {
    $out = bridgeSerializeArgs([fn ($x) => $x * 2]);

    expect($out)->toHaveCount(1)
        ->and($out[0])->toBeArray()
        ->and($out[0]['__php_callable__'])->toBeTrue()
        ->and($out[0]['callback_id'])->toBeString()
        ->and($out[0]['callback_id'])->not->toBeEmpty();
});

test('a PhpCallback wrapper becomes a __php_callable__ marker', function () {
    $out = bridgeSerializeArgs([new PhpCallback('strtoupper')]);

    expect($out[0]['__php_callable__'])->toBeTrue()
        ->and($out[0]['callback_id'])->toBeString();
});

test('a closure nested inside an array argument is caught', function () {
    $out = bridgeSerializeArgs([[1, 2, fn () => 3]]);

    expect($out[0])->toBeArray()
        ->and($out[0][0])->toBe(1)
        ->and($out[0][1])->toBe(2)
        ->and($out[0][2]['__php_callable__'])->toBeTrue();
});

test('a callable string is NOT auto-detected (stays data)', function () {
    // 'strlen' is a valid callable, but a bare string is ambiguous with data,
    // so it is passed through untouched unless wrapped in Py::callback().
    $out = bridgeSerializeArgs(['strlen', 'this is just data']);

    expect($out)->toBe(['strlen', 'this is just data']);
});

test('a callable string becomes a callback when wrapped in PhpCallback', function () {
    $out = bridgeSerializeArgs([new PhpCallback('strlen')]);

    expect($out[0]['__php_callable__'])->toBeTrue()
        ->and($out[0]['callback_id'])->toBeString();
});

test('an [object, method] pair is auto-detected as a callback', function () {
    $obj = new class {
        public function twice($x) { return $x * 2; }
    };
    $out = bridgeSerializeArgs([[$obj, 'twice']]);

    expect($out[0])->toBeArray()
        ->and($out[0]['__php_callable__'])->toBeTrue();
});

test('scalar arguments pass through unchanged', function () {
    $out = bridgeSerializeArgs([1, 2.5, true, null, 'hello']);

    expect($out)->toBe([1, 2.5, true, null, 'hello']);
});
