<?php

use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;

// Extras ("somepackage[geoip]") must survive persistence so reinstalls match the user's install

function savedExtras(Project $project, string $name): ?array
{
    foreach ($project->getPackages() as $package) {
        if ($package->name === $name) {
            return $package->extras;
        }
    }
    return null;
}

test('extras from the user command are persisted with the package', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'somepackage[geoip]'], " + somepackage==1.2.0\n + geoip2==4.8.0\n");

    expect(savedExtras($project, 'somepackage'))->toBe(['geoip']);
    expect(savedVersion($project, 'somepackage'))->toBe('^1.2.0');
    expect(lockedVersion($project, 'somepackage'))->toBe('1.2.0');
    // The extra's own dependencies stay transitive
    expect(savedVersion($project, 'geoip2'))->toBeNull();
});

test('extras combined with an explicit specifier keep both', function () {
    $project = new Project();
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'somepackage[geoip,cli]==1.2.0'], " + somepackage==1.2.0\n");

    expect(savedExtras($project, 'somepackage'))->toBe(['geoip', 'cli']);
    expect(savedVersion($project, 'somepackage'))->toBe('==1.2.0');
});

test('stored extras survive a batch reinstall', function () {
    $project = new Project();
    $project->addPackage(new Package('somepackage', new PackageVersion('^1.2.0'), extras: ['geoip']));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'somepackage[geoip]==1.2.0'], " + somepackage==1.2.0\n", user_command: ['install']);

    expect(savedExtras($project, 'somepackage'))->toBe(['geoip']);
});

test('a bare re-request keeps the stored extras', function () {
    $project = new Project();
    $project->addPackage(new Package('somepackage', new PackageVersion('^1.2.0'), extras: ['geoip']));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'somepackage'], " + somepackage==1.3.0\n");

    expect(savedExtras($project, 'somepackage'))->toBe(['geoip']);
});

test('newly requested extras replace the stored ones', function () {
    $project = new Project();
    $project->addPackage(new Package('somepackage', new PackageVersion('^1.2.0'), extras: ['geoip']));
    $manager = managerWithProject($project);

    persistInstalled($manager, ['install', 'somepackage[cli]'], " + somepackage==1.3.0\n");

    expect(savedExtras($project, 'somepackage'))->toBe(['cli']);
});

test('extras for an already-satisfied package are persisted even without uv output', function () {
    $project = new Project();
    $project->addPackage(new Package('requests', new PackageVersion('^2.31.0'), locked_version: '2.32.3'));
    $manager = managerWithProject($project);

    // requests itself is already installed, uv only adds the extra's dependency
    persistInstalled($manager, ['install', 'requests[socks]'], " + pysocks==1.7.1\n");

    expect(savedExtras($project, 'requests'))->toBe(['socks']);
    expect(lockedVersion($project, 'requests'))->toBe('2.32.3');
    expect(savedVersion($project, 'requests'))->toBe('^2.31.0');
});

test('the install spec includes the extras', function () {
    $pinned = new Package('somepackage', new PackageVersion('^1.2.0'), extras: ['geoip'], locked_version: '1.2.0');
    $unpinned = new Package('somepackage', new PackageVersion('^1.2.0'), extras: ['geoip', 'cli']);

    expect($pinned->getInstallSpec())->toBe('somepackage[geoip]==1.2.0');
    expect($unpinned->getInstallSpec())->toBe('somepackage[geoip,cli]>=1.2.0,<2.0.0');
});

test('extras round-trip through the composer.json array form', function () {
    $package = new Package('somepackage', new PackageVersion('^1.2.0'), extras: ['geoip']);

    $array = $package->toArray();
    expect($array['extras'])->toBe(['geoip']);

    $restored = Package::fromArray($array);
    expect($restored->extras)->toBe(['geoip']);

    // A comma-separated string form is tolerated too
    expect(Package::fromArray(['name' => 'p', 'version' => '*', 'extras' => 'geoip, cli'])->extras)->toBe(['geoip', 'cli']);
    expect(array_key_exists('extras', (new Package('plain', new PackageVersion('*')))->toArray()))->toBeFalse();
});
