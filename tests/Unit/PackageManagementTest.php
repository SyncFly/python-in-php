<?php

use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;

test('Package serializes index-url to array', function () {
    $package = new Package('torch', new PackageVersion('2.7.0+rocm6.3'), index_url: 'https://download.pytorch.org/whl/rocm6.3');
    $array = $package->toArray();

    expect($array['name'])->toBe('torch');
    expect($array['version'])->toBe('2.7.0+rocm6.3');
    expect($array['index-url'])->toBe('https://download.pytorch.org/whl/rocm6.3');
});

test('Package restores index-url from array', function () {
    $array = [
        'name'      => 'torch',
        'version'   => '2.7.0+rocm6.3',
        'index-url' => 'https://download.pytorch.org/whl/rocm6.3',
    ];
    $package = Package::fromArray($array);

    expect($package->name)->toBe('torch');
    expect($package->index_url)->toBe('https://download.pytorch.org/whl/rocm6.3');
});

test('Package serializes path to array', function () {
    $package = new Package('my-lib', new PackageVersion('1.0.0'), path: '/home/user/my-lib');
    $array = $package->toArray();

    expect($array['path'])->toBe('/home/user/my-lib');
    expect(array_key_exists('index-url', $array))->toBeFalse();
});

test('Package restores path from array', function () {
    $package = Package::fromArray(['name' => 'my-lib', 'version' => '1.0.0', 'path' => '/home/user/my-lib']);

    expect($package->path)->toBe('/home/user/my-lib');
    expect($package->index_url)->toBeNull();
});

test('Package without custom source omits extra keys from array', function () {
    $package = new Package('requests', new PackageVersion('2.28.0'));
    $array = $package->toArray();

    expect($array)->toHaveKeys(['name', 'version']);
    expect(array_key_exists('index-url', $array))->toBeFalse();
    expect(array_key_exists('path', $array))->toBeFalse();
});

test('Package getInstallSpec returns path when set', function () {
    $package = new Package('my-lib', new PackageVersion('1.0.0'), path: '/home/user/my-lib');
    expect($package->getInstallSpec())->toBe('/home/user/my-lib');
});

test('Package getInstallSpec returns name+version when no path', function () {
    $package = new Package('requests', new PackageVersion('2.28.0'));
    expect($package->getInstallSpec())->toBe('requests==2.28.0');
});

test('Package getInstallSpec uses index_url package with local version', function () {
    $package = new Package('torch', new PackageVersion('2.7.0+rocm6.3'), index_url: 'https://download.pytorch.org/whl/rocm6.3');
    // Spec must be "torch==2.7.0+rocm6.3", NOT "torch2.7.0+rocm6.3"
    expect($package->getInstallSpec())->toBe('torch==2.7.0+rocm6.3');
});

test('PackageVersion handles PEP 440 local version with + suffix', function () {
    expect((new PackageVersion('2.7.0+rocm6.3'))->convertToPip())->toBe('==2.7.0+rocm6.3');
    expect((new PackageVersion('2.0.0+cu118'))->convertToPip())->toBe('==2.0.0+cu118');
    expect((new PackageVersion('1.13.1+cpu'))->convertToPip())->toBe('==1.13.1+cpu');
});

test('PackageVersion exact version still works', function () {
    expect((new PackageVersion('2.28.0'))->convertToPip())->toBe('==2.28.0');
});

test('PackageVersion wildcard produces empty string', function () {
    expect((new PackageVersion('*'))->convertToPip())->toBe('');
});

test('PackageVersion caret range converts for pip', function () {
    expect((new PackageVersion('^1.2.3'))->convertToPip())->toBe('>=1.2.3,<2.0.0');
});
