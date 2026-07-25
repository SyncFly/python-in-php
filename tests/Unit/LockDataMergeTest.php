<?php

use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;

// python-in-php.lock pins are merged into the packages declared in composer.json

test('pins are merged into packages by their normalized name', function () {
    $project = new Project();
    $project->addPackage(new Package('Scikit_Learn', new PackageVersion('^1.4')));

    $orphans = $project->applyLockData(['packages' => [['name' => 'scikit-learn', 'version' => '1.4.2']]]);

    expect($orphans)->toBe([]);
    expect(lockedVersion($project, 'Scikit_Learn'))->toBe('1.4.2');
});

test('pins for packages no longer declared are reported as orphans', function () {
    $project = new Project();
    $project->addPackage(new Package('numpy', new PackageVersion('^1.24')));

    $orphans = $project->applyLockData(['packages' => [
        ['name' => 'numpy',    'version' => '1.26.4'],
        ['name' => 'requests', 'version' => '2.31.0'],
    ]]);

    expect($orphans)->toBe(['requests']);
    expect(lockedVersion($project, 'numpy'))->toBe('1.26.4');
});

test('malformed lock data is tolerated', function () {
    $project = new Project();
    $project->addPackage(new Package('numpy', new PackageVersion('^1.24')));

    expect($project->applyLockData(null))->toBe([]);
    expect($project->applyLockData(['packages' => 'oops']))->toBe([]);
    expect($project->applyLockData(['packages' => [['name' => 'numpy'], ['version' => '1.0'], 'junk']]))->toBe([]);
    expect(lockedVersion($project, 'numpy'))->toBeNull();
});

test('the lock array contains sorted pins of all tracked packages, included ones too', function () {
    $project = new Project();
    $project->setPythonVersion('3.13');
    $project->addPackage(new Package('numpy', new PackageVersion('^1.24'), locked_version: '1.26.4'));
    $project->addPackage(new Package('websockets', new PackageVersion('*'), from_included_package: true, locked_version: '15.0.1'));
    $project->addPackage(new Package('aiohttp', new PackageVersion('*'), locked_version: '3.9.5'));
    $project->addPackage(new Package(path: '/home/user/my-lib')); // unresolved path, nothing to pin
    $project->addPackage(new Package('pending', new PackageVersion('*'))); // not installed yet

    expect($project->toLockArray())->toBe([
        'python-version' => '3.13',
        'packages' => [
            ['name' => 'aiohttp',    'version' => '3.9.5'],
            ['name' => 'numpy',      'version' => '1.26.4'],
            ['name' => 'websockets', 'version' => '15.0.1'],
        ],
    ]);
});

// Pins that no longer fit their constraint are detected (checked best-effort via composer/semver)

test('a pin inside the constraint satisfies it', function () {
    $package = new Package('requests', new PackageVersion('^2.31.0'), locked_version: '2.32.3');
    expect($package->satisfiesConstraint())->toBeTrue();
});

test('a pin outside the constraint does not satisfy it', function () {
    $package = new Package('requests', new PackageVersion('^1.0'), locked_version: '2.32.3');
    expect($package->satisfiesConstraint())->toBeFalse();
});

test('the local version segment is ignored when checking the constraint', function () {
    $package = new Package('torch', new PackageVersion('^2.7.0'), locked_version: '2.7.0+rocm6.3');
    expect($package->satisfiesConstraint())->toBeTrue();
});

test('wildcard and unparseable constraints trust the pin', function () {
    expect((new Package('a', new PackageVersion('*'), locked_version: '1.0'))->satisfiesConstraint())->toBeTrue();
    expect((new Package('b', new PackageVersion('~=1.2'), locked_version: '9.9'))->satisfiesConstraint())->toBeTrue();
    expect((new Package('c', new PackageVersion('^1.0')))->satisfiesConstraint())->toBeTrue();
});
