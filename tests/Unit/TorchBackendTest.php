<?php

use Python_In_PHP\Plugin\Python\Services\UvService;

/**
 * Unit tests for the default --torch-backend=auto injection in UvService.
 */

function torchBackend(array $arguments): array
{
    $service = (new ReflectionClass(UvService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'withDefaultTorchBackend');
    $method->setAccessible(true);
    return $method->invoke($service, $arguments);
}

test('install commands get --torch-backend=auto by default', function () {
    expect(torchBackend(['install', 'torch']))
        ->toBe(['install', 'torch', '--torch-backend=auto']);
});

test('an explicit --torch-backend=value is preserved (=-form)', function () {
    expect(torchBackend(['install', 'torch', '--torch-backend=cpu']))
        ->toBe(['install', 'torch', '--torch-backend=cpu']);
});

test('an explicit --torch-backend value is preserved (space form)', function () {
    expect(torchBackend(['install', 'torch', '--torch-backend', 'cu126']))
        ->toBe(['install', 'torch', '--torch-backend', 'cu126']);
});

test('non-install pip subcommands are left untouched', function () {
    expect(torchBackend(['uninstall', 'torch']))->toBe(['uninstall', 'torch']);
    expect(torchBackend(['list']))->toBe(['list']);
    expect(torchBackend(['show', 'numpy']))->toBe(['show', 'numpy']);
});

test('the default is appended alongside other install flags', function () {
    expect(torchBackend(['install', 'numpy', '--index-url', 'https://example.test']))
        ->toBe(['install', 'numpy', '--index-url', 'https://example.test', '--torch-backend=auto']);
});
