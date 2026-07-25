<?php

use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;

// composer.json keeps an approximate constraint, the exact pin goes to python-in-php.lock

test('a bare install persists a caret constraint and an exact pin', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'requests'], " + requests==2.32.3\n");

    expect(savedVersion($project, 'requests'))->toBe('^2.32.3');
    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
});

test('an explicit specifier is stored verbatim as the constraint', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'requests==2.31.0'], " + requests==2.31.0\n");

    expect(savedVersion($project, 'requests'))->toBe('==2.31.0');
    expect(lockedVersion($project, 'requests'))->toBe('2.31.0');
});

test('a range specifier is stored verbatim as the constraint', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'requests>=2.0'], " + requests==2.32.3\n");

    expect(savedVersion($project, 'requests'))->toBe('>=2.0');
    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
});

test('an existing constraint survives a batch reinstall', function () {
    $project = new Project();
    $project->addPackage(new Package('numpy', new PackageVersion('^1.24')));
    $manager = managerWithProject($project);

    // The stored spec is appended to the batch command; the user only typed "install"
    persistInstalled($manager, ['install', 'numpy>=1.24.0,<2.0.0'], " + numpy==1.26.4\n", user_command: ['install']);

    expect(savedVersion($project, 'numpy'))->toBe('^1.24');
    expect(lockedVersion($project, 'numpy'))->toBe('1.26.4');
});

test('an existing constraint survives an upgrade within it', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^2.31.0'), locked_version: '2.31.0'));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', '--upgrade', 'requests>=2.31.0,<3.0.0'], " + requests==2.32.3\n", user_command: ['install', '--upgrade']);

    expect(savedVersion($project, 'requests'))->toBe('^2.31.0');
    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
});

test('an explicit request outside the old constraint widens it to a caret', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^1.0'), locked_version: '1.2.0'));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'requests'], " + requests==2.32.3\n");

    expect(savedVersion($project, 'requests'))->toBe('^2.32.3');
    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
});

test('a non-plain version gets an exact constraint instead of a caret', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'nightly-pkg'], " + nightly-pkg==2.0.0.post1\n");

    expect(savedVersion($project, 'nightly-pkg'))->toBe('==2.0.0.post1');
    expect(lockedVersion($project, 'nightly-pkg'))->toBe('2.0.0.post1');
});

// Migration: a legacy exact version in composer.json keeps pinning exactly

test('a legacy exact constraint still resolves to the same pip spec', function () {
    $package = new Package('requests', new PackageVersion('2.31.0'));

    expect($package->getInstallSpec())->toBe('requests==2.31.0');
});

test('the install spec prefers the lock pin over the constraint', function () {
    $package = new Package('requests', new PackageVersion('^2.31.0'), locked_version: '2.31.5');

    expect($package->getInstallSpec())->toBe('requests==2.31.5');
});

test('the install spec of a path package stays the path even when pinned', function () {
    $package = new Package('my-lib', new PackageVersion('^1.0'), path: '/home/user/my-lib', locked_version: '1.0.0');

    expect($package->getInstallSpec())->toBe('/home/user/my-lib');
});
