<?php

use Python_In_PHP\Plugin\Python\Services\UvPythonEnvironmentService;
use Python_In_PHP\Plugin\Python\Services\UvService;

/**
 * Unit tests for the default --torch-backend injection in UvService
 * and the uv version parsing used for self-upgrade.
 */

function torchBackend(array $arguments, string $os_family = 'Linux'): array
{
    $service = (new ReflectionClass(UvService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'withDefaultTorchBackend');
    $method->setAccessible(true);
    return $method->invoke($service, $arguments, $os_family);
}

afterEach(function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND');
});

test('install commands get --torch-backend=auto by default on Linux', function () {
    expect(torchBackend(['install', 'torch']))
        ->toBe(['install', 'torch', '--torch-backend=auto']);
});

test('install commands get --torch-backend=auto by default on Windows', function () {
    expect(torchBackend(['install', 'torch'], 'Windows'))
        ->toBe(['install', 'torch', '--torch-backend=auto']);
});

test('no --torch-backend is added on macOS (PyPI wheels ship MPS support)', function () {
    expect(torchBackend(['install', 'torch'], 'Darwin'))
        ->toBe(['install', 'torch']);
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

test('PYTHON_IN_PHP_TORCH_BACKEND overrides the default backend', function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND=rocm7.2');
    expect(torchBackend(['install', 'torch']))
        ->toBe(['install', 'torch', '--torch-backend=rocm7.2']);
});

test('PYTHON_IN_PHP_TORCH_BACKEND applies on macOS too', function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND=cpu');
    expect(torchBackend(['install', 'torch'], 'Darwin'))
        ->toBe(['install', 'torch', '--torch-backend=cpu']);
});

test('PYTHON_IN_PHP_TORCH_BACKEND=none disables the flag entirely', function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND=none');
    expect(torchBackend(['install', 'torch']))
        ->toBe(['install', 'torch']);
});

test('an explicit --torch-backend wins over the environment override', function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND=cpu');
    expect(torchBackend(['install', 'torch', '--torch-backend=cu128']))
        ->toBe(['install', 'torch', '--torch-backend=cu128']);
});

test('uv version is parsed from `uv --version` output', function () {
    $service = (new ReflectionClass(UvPythonEnvironmentService::class))->newInstanceWithoutConstructor();

    expect($service->parseUvVersion('uv 0.11.29'))->toBe('0.11.29');
    expect($service->parseUvVersion("uv 0.9.26 (0e0e2b7 2025-01-01)\n"))->toBe('0.9.26');
    expect($service->parseUvVersion('command not found'))->toBeNull();
    expect($service->parseUvVersion(''))->toBeNull();
});
