<?php

use Composer\Package\Locker;
use Python_In_PHP\Plugin\Python\Services\PythonLockFileService;

function makeLockProjectDir(): string
{
    $dir = sys_get_temp_dir() . '/pip_lock_' . uniqid();
    mkdir($dir, recursive: true);
    return $dir;
}

test('lock data round-trips through the file', function () {
    $dir = makeLockProjectDir();
    $service = new PythonLockFileService($dir);
    $data = ['python-version' => '3.12', 'packages' => [['name' => 'requests', 'version' => '2.32.3']]];

    expect($service->write($data))->toBeTrue();

    $read = (new PythonLockFileService($dir))->read();
    expect($read['python-version'])->toBe('3.12');
    expect($read['packages'])->toBe($data['packages']);
    expect($read['_readme'])->toBeString();
});

test('an unchanged write is skipped', function () {
    $dir = makeLockProjectDir();
    $service = new PythonLockFileService($dir);
    $data = ['python-version' => '3.12', 'packages' => []];

    expect($service->write($data))->toBeTrue();
    expect($service->write($data))->toBeFalse();
});

test('a missing lock file reads as null and is not created', function () {
    $dir = makeLockProjectDir();
    $service = new PythonLockFileService($dir);

    expect($service->read())->toBeNull();
    expect(is_file($dir . '/python-in-php.lock'))->toBeFalse();
});

test('a corrupt lock file reads as null', function () {
    $dir = makeLockProjectDir();
    file_put_contents($dir . '/python-in-php.lock', '{not json');

    expect((new PythonLockFileService($dir))->read())->toBeNull();
});

test('the composer.lock content-hash is repatched after a composer.json edit', function () {
    $dir = makeLockProjectDir();
    $composer_json = '{"name": "acme/app", "require": {"php": ">=8.2"}, "extra": {"python-in-php": {"packages": []}}}';
    file_put_contents($dir . '/composer.json', $composer_json);
    file_put_contents($dir . '/composer.lock', json_encode([
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => str_repeat('0', 32),
        'packages' => [],
    ], JSON_PRETTY_PRINT));

    (new PythonLockFileService($dir))->patchComposerLockHash();

    $lock = json_decode(file_get_contents($dir . '/composer.lock'), true);
    expect($lock['content-hash'])->toBe(Locker::getContentHash($composer_json));
    expect($lock['packages'])->toBe([]);
});

test('patching without a composer.lock is a no-op', function () {
    $dir = makeLockProjectDir();
    file_put_contents($dir . '/composer.json', '{}');

    (new PythonLockFileService($dir))->patchComposerLockHash();

    expect(is_file($dir . '/composer.lock'))->toBeFalse();
});
