<?php

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Services\UvPythonEnvironmentService;
use Symfony\Component\Console\Output\NullOutput;

// uv-related environment variables set by the user (Docker cache mounts, offline builds,
// a system uv) must be respected rather than overwritten with the library defaults.

afterEach(function () {
    foreach (['UV_BIN', 'UV_PYTHON_INSTALL_DIR', 'UV_PYTHON_PREFERENCE'] as $env) {
        putenv($env);
    }
});

function uvEnvironmentService(): UvPythonEnvironmentService
{
    return new UvPythonEnvironmentService('/tmp/lib', '/tmp/bin', new OutputService(new NullOutput()));
}

test('the managed Python location and preference default to the vendor bin dir', function () {
    uvEnvironmentService();

    expect(getenv('UV_PYTHON_INSTALL_DIR'))->toBe('/tmp/bin' . DIRECTORY_SEPARATOR . 'python')
        ->and(getenv('UV_PYTHON_PREFERENCE'))->toBe('only-managed');
});

test('user-provided UV_PYTHON_INSTALL_DIR and UV_PYTHON_PREFERENCE are kept', function () {
    putenv('UV_PYTHON_INSTALL_DIR=/cache/uv-python');
    putenv('UV_PYTHON_PREFERENCE=system');

    uvEnvironmentService();

    expect(getenv('UV_PYTHON_INSTALL_DIR'))->toBe('/cache/uv-python')
        ->and(getenv('UV_PYTHON_PREFERENCE'))->toBe('system');
});

test('UV_BIN overrides the downloaded uv binary', function () {
    $service = uvEnvironmentService();
    $default = $service->getUvBinPath();

    putenv('UV_BIN=/opt/uv/uv');

    expect($default)->toStartWith('/tmp/bin')
        ->and($service->getUvBinPath())->toBe('/opt/uv/uv');
});

test('installUvIfMissing() does not download when UV_BIN is set, and validates it', function () {
    putenv('UV_BIN=/definitely/missing/uv');

    expect(fn () => uvEnvironmentService()->installUvIfMissing())
        ->toThrow(RuntimeException::class, 'UV_BIN points to a missing file');
});
