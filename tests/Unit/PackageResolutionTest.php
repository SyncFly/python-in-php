<?php

use Python_In_PHP\Plugin\Python\PythonManager;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;

/** Invoke a private PythonManager method without running its (heavy) constructor. */
function invokePrivate(string $method, array $args): mixed
{
    $manager = (new ReflectionClass(PythonManager::class))->newInstanceWithoutConstructor();
    $ref = new ReflectionMethod($manager, $method);
    $ref->setAccessible(true);
    return $ref->invoke($manager, ...$args);
}

function makeTempDir(): string
{
    $dir = sys_get_temp_dir() . '/pip_pkg_' . uniqid();
    mkdir($dir, recursive: true);
    return $dir;
}

// --- name detection (needed for uninstall + doc generation of path installs) ---

test('reads package name from PEP 621 pyproject.toml', function () {
    $dir = makeTempDir();
    file_put_contents($dir . '/pyproject.toml', "[build-system]\nrequires = [\"hatchling\"]\n\n[project]\nname = \"My_Cool.Pkg\"\nversion = \"1.2.3\"\n");

    expect(invokePrivate('readPackageNameFromPath', [$dir]))->toBe('My_Cool.Pkg');
});

test('reads package name from setup.cfg metadata', function () {
    $dir = makeTempDir();
    file_put_contents($dir . '/setup.cfg', "[metadata]\nname = legacy-pkg\nversion = 0.1\n");

    expect(invokePrivate('readPackageNameFromPath', [$dir]))->toBe('legacy-pkg');
});

test('reads package name from egg-info PKG-INFO', function () {
    $dir = makeTempDir();
    mkdir($dir . '/mypkg.egg-info', recursive: true);
    file_put_contents($dir . '/mypkg.egg-info/PKG-INFO', "Metadata-Version: 2.1\nName: mypkg\nVersion: 3.0\n");

    expect(invokePrivate('readPackageNameFromPath', [$dir]))->toBe('mypkg');
});

test('reads package name from a wheel/sdist filename', function () {
    expect(invokePrivate('readPackageNameFromPath', ['/some/dir/requests-2.31.0-py3-none-any.whl']))->toBe('requests')
        ->and(invokePrivate('readPackageNameFromPath', ['/some/dir/scikit_learn-1.4.0.tar.gz']))->toBe('scikit_learn');
});

test('returns null when the path carries no discoverable name', function () {
    $dir = makeTempDir(); // empty directory, no metadata files

    expect(invokePrivate('readPackageNameFromPath', [$dir]))->toBeNull()
        ->and(invokePrivate('readPackageNameFromPath', ['/nonexistent/path']))->toBeNull();
});

test('normalizes distribution names per PEP 503', function () {
    expect(invokePrivate('normalizePackageName', ['Foo_Bar']))->toBe('foo-bar')
        ->and(invokePrivate('normalizePackageName', ['My.Cool..Pkg']))->toBe('my-cool-pkg')
        ->and(invokePrivate('normalizePackageName', ['requests']))->toBe('requests');
});

// --- storage of a path install: name (for uninstall/docs) + path (for reinstall) ---

test('a resolved path install keeps name, version and path', function () {
    $package = new Package('my-lib', new PackageVersion('1.0.0'), path: '/home/user/my-lib');

    expect($package->toArray())->toBe([
        'name'    => 'my-lib',
        'version' => '1.0.0',
        'path'    => '/home/user/my-lib',
    ])->and($package->getInstallSpec())->toBe('/home/user/my-lib'); // reinstalls from path
});

test('an unresolved path install falls back to path only', function () {
    $package = new Package(path: '/home/user/my-lib');

    expect($package->toArray())->toBe(['path' => '/home/user/my-lib'])
        ->and($package->name)->toBeNull()
        ->and($package->getInstallSpec())->toBe('/home/user/my-lib');
});

test('getKey() identifies packages by name, falling back to path when unknown', function () {
    expect((new Package('requests', new PackageVersion('2.31.0')))->getKey())->toBe('requests')
        ->and((new Package('my-lib', path: '/home/user/my-lib'))->getKey())->toBe('my-lib')
        ->and((new Package(path: '/home/user/my-lib'))->getKey())->toBe('/home/user/my-lib');
});

test('the project de-duplicates a resolved path install by its name', function () {
    $project = new Project();
    $project->addPackage(new Package('my-lib', new PackageVersion('1.0.0'), path: '/a/my-lib'));
    $project->addPackage(new Package('my-lib', new PackageVersion('1.1.0'), path: '/b/my-lib')); // same name

    expect($project->getPackages())->toHaveCount(1);
});

test('unresolved path installs are keyed (and de-duplicated) by path', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('2.31.0')));
    $project->addPackage(new Package(path: '/home/user/my-lib'));
    $project->addPackage(new Package(path: '/home/user/my-lib')); // same path again

    expect($project->getPackages())->toHaveCount(2)
        ->and($project->isAdded(new Package(path: '/home/user/my-lib')))->toBeTrue();
});
