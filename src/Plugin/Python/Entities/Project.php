<?php

namespace Python_In_PHP\Plugin\Python\Entities;

use Python_In_PHP\Plugin\Utils;

class Project
{
    public string $python_version = '3.12';

    /** @var Package[]  */
    public array $packages = [];

    public function addPackage(Package $package): void
    {
        $this->packages[$package->getKey()] = $package;
    }

    public function removePackage(Package $package): void
    {
        unset($this->packages[$package->getKey()]);
    }

    /**
     * @return Package[]
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    public function getPackagesFromRoot()
    {
        $list = [];
        foreach ($this->packages as $package) {
            if (!$package->from_included_package)
                $list[] = $package;
        }
        return $list;
    }

    public function setPythonVersion(string $python_version): void
    {
        $this->python_version = $python_version;
    }

    public function getPythonVersion(): string
    {
        return $this->python_version;
    }

    /** Writes the constraints to composer.json only when the section actually changed; returns whether it wrote. */
    public function saveInComposerJson(string $composer_json_path): bool
    {
        $composer_json = json_decode(file_get_contents($composer_json_path), true);
        $section = [
            'python-version' => $this->python_version,
            'packages' => array_map(fn($package) => $package->toArray(), $this->getPackagesFromRoot())
        ];
        if (($composer_json['extra']['python-in-php'] ?? null) === $section) {
            return false;
        }
        $composer_json['extra']['python-in-php'] = $section;
        file_put_contents($composer_json_path, json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return true;
    }

    /** Merges exact pins from python-in-php.lock data into tracked packages; returns names of orphaned pins. */
    public function applyLockData(?array $data): array
    {
        $entries = $data['packages'] ?? null;
        if (!is_array($entries)) {
            return [];
        }

        $by_name = [];
        foreach ($this->packages as $package) {
            if ($package->name !== null) {
                $by_name[Utils::normalizePackageName($package->name)] = $package;
            }
        }

        $orphans = [];
        foreach ($entries as $entry) {
            $name    = $entry['name'] ?? null;
            $version = $entry['version'] ?? null;
            if (!is_string($name) || !is_string($version)) {
                continue;
            }
            $package = $by_name[Utils::normalizePackageName($name)] ?? null;
            if ($package === null) {
                $orphans[] = $name;
                continue;
            }
            $package->locked_version = $version;
        }
        return $orphans;
    }

    /** Lock file representation: the python version and exact pins of every tracked package. */
    public function toLockArray(): array
    {
        $packages = [];
        foreach ($this->packages as $package) {
            $entry = $package->toLockArray();
            if ($entry !== null) {
                $packages[] = $entry;
            }
        }
        usort($packages, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['python-version' => $this->python_version, 'packages' => $packages];
    }

    public static function loadFromComposerExtras(iterable $extras): self
    {
        $project = new self();

        foreach ($extras as $extra) {
            $properties = $extra['properties'];
            if (!empty($properties['packages'])) {
                foreach ($properties['packages'] as $package_array) {
                    $package = Package::fromArray($package_array);
                    $package->from_included_package = !$extra['is_root'];

                    // A root-declared package must not be overridden by the same package from an included library
                    $existing = $project->packages[$package->getKey()] ?? null;
                    if ($package->from_included_package && $existing !== null && !$existing->from_included_package) {
                        continue;
                    }

                    $project->addPackage($package);
                }
            }
            if (!empty($properties['python-version'])) {
                $project->setPythonVersion($properties['python-version']);
            }
        }

        return $project;
    }

    public function isAdded(Package $package): bool
    {
        return isset($this->packages[$package->getKey()]);
    }
}