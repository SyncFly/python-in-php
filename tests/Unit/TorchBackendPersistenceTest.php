<?php

use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;

// The auto-detected GPU backend ("+cu126"/"+rocm7.2") must not be written to the lock pin,
// so the environment re-detects it per machine. A user- or index-url-pinned backend stays.

afterEach(function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND');
});

test('an auto-detected GPU backend is stripped from the lock pin', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'torch'], " + torch==2.7.0+rocm6.3\n");

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0');
    expect(savedVersion($project, 'torch'))->toBe('^2.7.0');
});

test('a torch install without a local segment is pinned unchanged', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'torch'], " + torch==2.7.0\n");

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0');
    expect(savedVersion($project, 'torch'))->toBe('^2.7.0');
});

test('an --index-url pins the backend, so the local segment is kept in the pin', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    $command = ['install', 'torch', '--index-url', 'https://download.pytorch.org/whl/rocm6.3'];
    persistInstalled($manager, $command, " + torch==2.7.0+rocm6.3\n", 'https://download.pytorch.org/whl/rocm6.3');

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0+rocm6.3');
    expect(savedVersion($project, 'torch'))->toBe('^2.7.0');
});

test('a user-pinned local version is kept in both the pin and the constraint', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'torch==2.7.0+cu118'], " + torch==2.7.0+cu118\n");

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0+cu118');
    expect(savedVersion($project, 'torch'))->toBe('==2.7.0+cu118');
});

test('an explicit PYTHON_IN_PHP_TORCH_BACKEND keeps the local segment in the pin', function () {
    putenv('PYTHON_IN_PHP_TORCH_BACKEND=rocm6.3');
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'torch'], " + torch==2.7.0+rocm6.3\n");

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0+rocm6.3');
    expect(savedVersion($project, 'torch'))->toBe('^2.7.0');
});

test('a package already tracked with an index_url keeps its local segment in the pin', function () {
    $project = new Project();
    $project->addPackage(new Package('torch', new PackageVersion('2.6.0+rocm6.3'), index_url: 'https://download.pytorch.org/whl/rocm6.3'));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'torch'], " + torch==2.7.0+rocm6.3\n");

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0+rocm6.3');
    // The explicit request moved the version outside the old exact constraint, widening it
    expect(savedVersion($project, 'torch'))->toBe('^2.7.0');
});
