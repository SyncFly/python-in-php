<?php

use Python_In_PHP\Plugin\Python\PythonManager;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;

// Packages shipped by included libraries must not leak into the consumer's composer.json

function managerWithProject(Project $project): PythonManager
{
    $manager = (new ReflectionClass(PythonManager::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PythonManager::class, 'project');
    $property->setAccessible(true);
    $property->setValue($manager, $project);
    return $manager;
}

function persistInstalled(PythonManager $manager, array $command, string $output, ?string $index_url = null, ?array $user_command = null): array
{
    $method = new ReflectionMethod($manager, 'persistInstalledPackages');
    $method->setAccessible(true);
    return $method->invoke($manager, $command, ['output' => $output, 'code' => 0], $index_url, null, $user_command ?? $command);
}

function savedVersion(Project $project, string $name): ?string
{
    foreach ($project->getPackages() as $package) {
        if ($package->name === $name) {
            return $package->version->toString();
        }
    }
    return null;
}

function lockedVersion(Project $project, string $name): ?string
{
    foreach ($project->getPackages() as $package) {
        if ($package->name === $name) {
            return $package->locked_version;
        }
    }
    return null;
}

function rootPackageNames(Project $project): array
{
    return array_map(fn($package) => $package->name, $project->getPackagesFromRoot());
}

test('packages from included libraries are excluded from the root package list', function () {
    $project = Project::loadFromComposerExtras([
        [
            'properties' => ['packages' => [
                ['name' => 'websockets', 'version' => '*'],
                ['name' => 'setuptools', 'version' => '*'],
                ['name' => 'wheel',      'version' => '*'],
            ]],
            'is_root' => false,
        ],
    ]);

    expect($project->getPackagesFromRoot())->toBe([]);
    expect($project->isAdded(new Package('websockets')))->toBeTrue();
});

test('a root-declared package is not hijacked by the same package from an included library', function () {
    $project = Project::loadFromComposerExtras([
        [
            'properties' => ['packages' => [['name' => 'websockets', 'version' => '^15.0']]],
            'is_root' => true,
        ],
        [
            'properties' => ['packages' => [['name' => 'websockets', 'version' => '*']]],
            'is_root' => false,
        ],
    ]);

    $root = $project->getPackagesFromRoot();
    expect($root)->toHaveCount(1);
    expect($root[0]->version->toString())->toBe('^15.0');
});

test('reinstalling a tracked included package does not promote it to a root package', function () {
    $project = new Project();
    $project->addPackage(new Package('websockets', new PackageVersion('*'), from_included_package: true));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'numpy'], " + numpy==2.0.0\n + websockets==15.0.1\n");

    expect(rootPackageNames($project))->toBe(['numpy']);
});

test('explicitly installing an included package promotes it to a root package', function () {
    $project = new Project();
    $project->addPackage(new Package('websockets', new PackageVersion('*'), from_included_package: true));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'websockets'], " + websockets==15.0.1\n");

    expect(rootPackageNames($project))->toBe(['websockets']);
});

test('included packages are not written to the consumer composer.json', function () {
    $project = Project::loadFromComposerExtras([
        [
            'properties' => ['packages' => [['name' => 'numpy', 'version' => '^1.24']]],
            'is_root' => true,
        ],
        [
            'properties' => ['packages' => [
                ['name' => 'websockets', 'version' => '*'],
                ['name' => 'setuptools', 'version' => '*'],
                ['name' => 'wheel',      'version' => '*'],
            ]],
            'is_root' => false,
        ],
    ]);

    $composer_json = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
    file_put_contents($composer_json, '{}');
    $project->saveInComposerJson($composer_json);

    $saved = json_decode(file_get_contents($composer_json), true);
    $names = array_column($saved['extra']['python-in-php']['packages'], 'name');

    expect($names)->toBe(['numpy']);
    unlink($composer_json);
});

test('an unchanged save does not rewrite composer.json', function () {
    $project = Project::loadFromComposerExtras([
        [
            'properties' => ['packages' => [['name' => 'numpy', 'version' => '^1.24']]],
            'is_root' => true,
        ],
    ]);

    $composer_json = tempnam(sys_get_temp_dir(), 'composer_') . '.json';
    file_put_contents($composer_json, '{}');

    expect($project->saveInComposerJson($composer_json))->toBeTrue();
    $written = file_get_contents($composer_json);

    expect($project->saveInComposerJson($composer_json))->toBeFalse();
    expect(file_get_contents($composer_json))->toBe($written);
    unlink($composer_json);
});
