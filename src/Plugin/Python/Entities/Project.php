<?php

namespace Python_In_PHP\Plugin\Python\Entities;

use Composer\Composer;
use Composer\Config\JsonConfigSource;

class Project
{
    public string $python_version = '3.12';

    /** @var Package[]  */
    public array $packages = [];

    public function addPackage(Package $package): void
    {
        $this->packages[$package->name] = $package;
    }

    public function removePackage(Package $package): void
    {
        unset($this->packages[$package->name]);
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

    public function saveAsLockFile(string $file_path): void
    {
        file_put_contents($file_path, serialize($this));
    }

    public static function loadFromLockFile(string $file_path): self
    {
        return unserialize(file_get_contents($file_path));
    }

    public function saveInComposerJson(string $composer_json_path)
    {
        $composer_json = json_decode(file_get_contents($composer_json_path), true);
        $composer_json['extra']['python-in-php'] = [
            'python-version' => $this->python_version,
            'packages' => array_map(fn($package) => $package->toArray(), $this->getPackagesFromRoot())
        ];
        file_put_contents($composer_json_path, json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        return isset($this->packages[$package->name]);
    }
}