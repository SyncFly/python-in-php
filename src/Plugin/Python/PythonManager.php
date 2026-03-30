<?php

namespace Python_In_PHP\Plugin\Python;

use Composer\Composer;
use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Python_In_PHP\Plugin\Python\Services\UvPythonEnvironmentService;
use Python_In_PHP\Plugin\Python\Services\UvService;

class PythonManager
{
    private string $package_vendor = 'syncfly';
    private string $package_name = 'python-in-php';

    private string $dir;
    private string $bin_dir;
    private string $python_bin_path;
    private bool $is_new_environment;

    private Composer $composer;
    private OutputService $output;

    private UvPythonEnvironmentService $python_environment;
    private UvService $python_service;
    private PhpDocGeneratorService $php_docs;

    private Project $project;

    public function __construct(string $dir, string $bin_dir, Composer $composer, OutputService $output)
    {
        $this->composer = $composer;
        $this->output = $output;

        $this->bin_dir = $bin_dir . DIRECTORY_SEPARATOR . $this->package_name;
        if (!is_dir($this->bin_dir)) mkdir($this->bin_dir, recursive: true);
        $this->bin_dir = realpath($this->bin_dir);

        $this->dir = $dir . DIRECTORY_SEPARATOR . $this->package_vendor . DIRECTORY_SEPARATOR . $this->package_name;
        if (!is_dir($this->dir)) mkdir($this->dir, recursive: true);
        $this->dir = realpath($this->dir);

        $this->project = Project::loadFromComposerExtras($this->getAllComposerExtras());

        $this->python_environment = new UvPythonEnvironmentService($this->dir, $this->bin_dir, $this->output);
        $this->python_environment->installUvIfMissing();
        $this->is_new_environment = $this->python_environment->createEnvironmentIfMissing($this->project->getPythonVersion());
        $this->python_service = new UvService($this->python_environment, $this->output);

        $this->python_bin_path = $this->python_environment->getPythonBinPath();

        //if ($this->is_new_environment) $this->reinstallAllPackages();

        $this->php_docs = new PhpDocGeneratorService($this->dir, $this->output);
        //if ($this->is_new_environment) $this->refreshPhpDocsForAllPackages();
    }

    public function runPipCommand(array $command)
    {
        if ($command[0] == 'install') {
            $this->handleInstall($command);
        }
        if ($command[0] == 'uninstall') {
            $this->handleUninstall($command);
        }
        elseif (in_array($command[0], ['list', 'show', 'tree', 'check'])) {
            $this->handleOthers($command);
        }
        else {
            $result = $this->python_service->executePipCommand($this->project, $command);
        }
    }

    public function handleInstall(array $command = ['install'])
    {
        if (!in_array('--no-deps', $command)){
            foreach ($this->project->getPackages() as $package) {
                if (!$this->commandIncludesPackage($command, $package)){
                    if (in_array('--upgrade', $command)) $command[] = $package->name;
                    else $command[] = $package->name . $package->version->convertToPip();
                }
            }
        }

        $result = $this->python_service->executePipCommand($this->project, $command);

        $this->output->displayMessage($result['output']);

        $packages_to_refresh = [];
        $packages = $this->parseAddedPackages($result['output']);
        foreach ($packages as $package) {
            if ($this->project->isAdded($package) || $this->commandIncludesPackage($command, $package) || in_array('--no-deps', $command) || $this->commandIncludes($command, '/')) {
                $this->project->addPackage($package);
                $packages_to_refresh[] = $package;
            }
        }
        $this->saveProject();
        $this->php_docs->refreshPhpDocs($packages_to_refresh);
    }

    public function handleUninstall(array $command)
    {
        $result = $this->python_service->executePipCommand($this->project, $command);

        $this->output->displayMessage($result['output']);

        $packages = $this->parseRemovedPackages($result['output']);
        foreach ($packages as $package) {
            $this->project->removePackage($package);
        }
        $this->saveProject();
        $this->php_docs->deletePhpDocs($packages);
    }

    public function handleOthers(array $command)
    {
        $result = $this->python_service->executePipCommand($this->project, $command);
        $this->output->displayMessage($result['output']);
    }

    public function commandIncludesPackage(array $command, Package $package): bool
    {
        $name = preg_quote($package->name, '/');
        $name = str_replace(['\-', '\_', '-', '_'], '.', $name);
        $pattern = '/\b' . $name . '\b/';
        foreach ($command as $part) {
            if (preg_match($pattern, $part)) return true;
        }
        return false;
    }

    public function commandIncludes(array $command, string $text): bool
    {
        $pattern = '/' . preg_quote($text, '/') . '/';
        foreach ($command as $part) {
            if (preg_match($pattern, $part)) return true;
        }
        return false;
    }

    public function parseAddedPackages(string $output): array
    {
        preg_match_all('/^\s*\+\s+(.+)==(.+)$/m', $output, $matches, PREG_SET_ORDER);

        $packages = [];
        foreach ($matches as $m) {
            $packages[] = new Package(trim($m[1]), new PackageVersion(trim($m[2])));
        }

        return $packages;
    }

    public function parseRemovedPackages(string $output): array
    {
        preg_match_all('/^\s*-\s+(.+)==(.+)$/m', $output, $matches, PREG_SET_ORDER);

        $packages = [];
        foreach ($matches as $m) {
            $packages[] = new Package(trim($m[1]), new PackageVersion(trim($m[2])));
        }

        return $packages;
    }

    private function walkAndParsePackagesArguments(iterable $packages): array
    {
        $result = [];

        foreach ($packages as $name) {
            $name = str_replace('"', '', $name);
            if (str_contains($name, ':')) {
                [$name, $version] = explode(':', $name);
            }

            $package = new Package($name, new PackageVersion($version ?? '*'));
            $result[$name] = $package;
        }

        return $result;
    }

    public function addPackages(iterable $arguments)
    {
        $packages = $this->walkAndParsePackagesArguments($arguments);
        $need_to_refresh = false;

        foreach ($packages as $package) {;
            $is_successful = $this->installPackage($package);

            if ($is_successful) {
                $need_to_refresh = true;
                $this->project->addPackage($package);
            }
        }

        $this->saveProject();

        if ($need_to_refresh) $this->php_docs->refreshPhpDocs($packages);
    }

    public function removePackages(iterable $arguments)
    {
        $packages = $this->walkAndParsePackagesArguments($arguments);

        foreach ($packages as $package) {
            $this->uninstallPackage($package);
            $this->project->removePackage($package);
        }

        $this->saveProject();

        $this->php_docs->deletePhpDocs($packages);
    }

    public function updatePackages(iterable $arguments)
    {
        $packages = $this->walkAndParsePackagesArguments($arguments);
        $need_to_refresh = false;

        foreach ($packages as $package) {
            $is_successful = $this->updatePackage($package);

            if ($is_successful) {
                $need_to_refresh = true;
                $this->project->addPackage($package);
            }
        }

        $this->saveProject();

        if ($need_to_refresh) $this->php_docs->refreshPhpDocs($packages);
    }

    public function updateAll()
    {
        $packages = $this->project->getPackagesFromRoot();
        $need_to_refresh = false;

        foreach ($packages as $package) {
            $is_successful = $this->updatePackage($package);

            if ($is_successful) {
                $need_to_refresh = true;
                $this->project->addPackage($package);
            }
        }

        $this->saveProject();

        if ($need_to_refresh) $this->php_docs->refreshPhpDocs($packages);
    }

    public function installProject()
    {
        if (!$this->is_new_environment) {
            $need_to_refresh = $this->reinstallAllPackages();
            if ($need_to_refresh) $this->refreshPhpDocsForAllPackages();
        }
    }

    public function reinstallProject()
    {
        $version = $this->project->getPythonVersion();
        $this->python_environment->deleteAllEnvironments();
        $this->python_environment->createEnvironment($version);

        $this->output->displayMessage("The Python $version environment has been set up ✅");
        $this->output->displayMessage("Installing the project dependencies...");

        $this->reinstallAllPackages();
        $this->refreshPhpDocsForAllPackages();
    }

    public function dumpAutoload()
    {
        $this->refreshPhpDocsForAllPackages();
    }

    public function setPythonVersion(string $version)
    {
        $is_created = $this->python_environment->isEnvironmentCreated($version) && $this->project->getPythonVersion() == $version;
        if (!$is_created) {
            $this->python_environment->deleteAllEnvironments();
            $this->python_environment->createEnvironment($version);

            $this->project->setPythonVersion($version);

            $this->output->displayMessage("The Python $version environment has been set up ✅");
            $this->output->displayMessage("Installing the project dependencies...");

            $need_to_refresh = $this->reinstallAllPackages();
        }
        else {
            $this->output->displayMessage("The Python environment is already at version $version ℹ️");
        }

        $this->saveProject();

        if (!$is_created && $need_to_refresh) $this->refreshPhpDocsForAllPackages();
    }

    public function run(array $arguments): void
    {
        echo $this->python_service->runPython($arguments, $this->project);
    }

    private function installPackage(Package $package): bool
    {
        [$is_successful, $message] = $this->python_service->installPackage($package, $this->project);

        $status = $is_successful ? "successfully installed" : "was not installed. $message";
        $icon = $is_successful ? "✅" : "❌";
        $this->output->displayMessage("$icon \"$package->name\" $status");

        return $is_successful;
    }

    private function uninstallPackage(Package $package): void
    {
        $is_successful = $this->python_service->uninstallPackage($package, $this->project);

        $status = $is_successful ? "successfully uninstalled" : "is not installed";
        $icon = $is_successful ? "☑️" : "ℹ️";
        $this->output->displayMessage("$icon \"$package->name\" $status");
    }

    private function updatePackage(Package $package): bool
    {
        [$is_successful, $is_performed, $message] = $this->python_service->updatePackage($package, $this->project);

        $status = $is_successful ? ($is_performed ? "successfully updated to a new version" : "is already the newest version") : "was not updated. $message";
        $icon = $is_successful ? ($is_performed ? "⬆️" : "ℹ️") : "❌";
        $this->output->displayMessage("$icon \"$package->name\" $status");

        return $is_successful && $is_performed;
    }

    private function reinstallAllPackages(): bool
    {
        $packages = $this->project->getPackages();
        $need_to_refresh = false;

        foreach ($packages as $package) {
            $is_successful = $this->installPackage($package);

            if ($is_successful) $need_to_refresh = true;
            if (!$is_successful) $this->project->removePackage($package);
        }

        return $need_to_refresh;
    }

    /**
     * @return array{name: string, version: string, python-in-php: array}[]
     */
    private function getAllComposerExtras(): array
    {
        $allExtras = [];

        // Root composer.json
        $rootPackage = $this->composer->getPackage();
        $rootExtra = $rootPackage->getExtra();
        if (!empty($rootExtra[$this->package_name])) {
            $allExtras['root'] = [
                'name' => $rootPackage->getName(),
                'version' => $rootPackage->getVersion(),
                'properties' => $rootExtra[$this->package_name],
                'is_root' => $rootPackage->getName() == '__root__',
            ];
        }

        // All installed packages
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($localRepo->getPackages() as $package) {
            $extra = $package->getExtra();
            if (!empty($extra[$this->package_name])) {
                $allExtras[$package->getName()] = [
                    'name' => $package->getName(),
                    'version' => $package->getVersion(),
                    'properties' => $extra[$this->package_name],
                    'is_root' => $package->getName() == '__root__',
                ];
            }
        }

        return $allExtras;
    }

    private function saveProject()
    {
        $composer_json_path = dirname($this->composer->getConfig()->get('vendor-dir')) . '/composer.json';
        $this->project->saveInComposerJson($composer_json_path);
    }

    private function refreshPhpDocsForAllPackages(): void
    {
        $this->php_docs->refreshPhpDocs($this->project->getPackages(), true);
    }
}