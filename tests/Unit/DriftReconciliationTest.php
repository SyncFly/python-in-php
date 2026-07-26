<?php

use Python_In_PHP\Plugin\Python\PythonManager;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;
use Python_In_PHP\Plugin\Python\Services\UvService;

// An interrupted run leaves the environment ahead of the records; uv reports versions only
// when it changes them, so the pins must be re-synced from "uv pip freeze" after installs

function managerWithFreeze(Project $project, string $freeze_output): PythonManager
{
    $manager = managerWithProject($project);
    $service = new class($freeze_output) extends UvService {
        public function __construct(private string $freeze_output) {}
        public function executePipCommand(Project $project, array $arguments): array
        {
            return ['code' => 0, 'output' => $this->freeze_output];
        }
    };
    $property = new ReflectionProperty(PythonManager::class, 'python_service');
    $property->setAccessible(true);
    $property->setValue($manager, $service);
    return $manager;
}

function syncPins(PythonManager $manager): void
{
    $method = new ReflectionMethod($manager, 'syncPinsWithEnvironment');
    $method->setAccessible(true);
    $method->invoke($manager);
}

function reconcileConstraints(PythonManager $manager, array $user_command): void
{
    $method = new ReflectionMethod($manager, 'reconcileRequestedConstraints');
    $method->setAccessible(true);
    $method->invoke($manager, $user_command);
}

test('a drifted pin is realigned with the environment', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^2.31'), locked_version: '2.31.0'));
    $manager = managerWithFreeze($project, "requests==2.32.3\ncertifi==2026.7.22\n");

    syncPins($manager);

    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
    expect(savedVersion($project, 'requests'))->toBe('^2.31');
    // The transitive dependency stays untracked
    expect(savedVersion($project, 'certifi'))->toBeNull();
});

test('a missing pin is filled from the environment', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^2.31')));
    $manager = managerWithFreeze($project, "requests==2.32.3\n");

    syncPins($manager);

    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
});

test('a pin matching the environment keeps its local-segment decision', function () {
    $project = new Project();
    $project->addPackage(new Package('torch', new PackageVersion('^2.7.0'), locked_version: '2.7.0'));
    $manager = managerWithFreeze($project, "torch==2.7.0+rocm6.3\n");

    syncPins($manager);

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0');
});

test('a drifted pin keeps the GPU segment only when the source pins it', function () {
    $project = new Project();
    $project->addPackage(new Package('torch', new PackageVersion('*'), index_url: 'https://download.pytorch.org/whl/rocm6.3', locked_version: '2.6.0+rocm6.3'));
    $project->addPackage(new Package('torchvision', new PackageVersion('*'), locked_version: '0.21.0'));
    $manager = managerWithFreeze($project, "torch==2.7.0+rocm6.3\ntorchvision==0.22.0+rocm6.3\n");

    syncPins($manager);

    expect(lockedVersion($project, 'torch'))->toBe('2.7.0+rocm6.3');
    expect(lockedVersion($project, 'torchvision'))->toBe('0.22.0');
});

test('a package absent from the environment keeps its pin', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^2.31'), locked_version: '2.31.0'));
    $manager = managerWithFreeze($project, "numpy==1.26.4\n");

    syncPins($manager);

    expect(lockedVersion($project, 'requests'))->toBe('2.31.0');
});

test('an interrupted upgrade retry heals the records', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^2.31'), locked_version: '2.31.0'));
    // The interrupted run already upgraded the environment, so the retry reports nothing
    $manager = managerWithFreeze($project, "requests==2.32.3\n");

    persistInstalled($manager, ['install', '--upgrade', 'requests>=2.31.0,<3.0.0'], "Audited 1 package\n", user_command: ['install', '--upgrade']);
    syncPins($manager);
    reconcileConstraints($manager, ['install', '--upgrade']);

    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
    expect(savedVersion($project, 'requests'))->toBe('^2.31');
});
