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
        if (!$this->is_new_environment) {
            $this->python_environment->restoreSymlinkIfMissing($this->project->getPythonVersion());
        }
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
        $command_index_url = $this->extractOptionValue($command, '--index-url');
        $command_path      = $this->extractPathFromCommand($command);
        $has_custom_source = $command_index_url !== null || $command_path !== null;

        if (!$has_custom_source && !in_array('--no-deps', $command)) {
            // Standard flow: append stored packages that have no custom source to the main
            // command so they all get reinstalled in a single uv invocation.
            foreach ($this->project->getPackages() as $package) {
                if (!$this->commandIncludesPackage($command, $package)
                    && $package->index_url === null && $package->path === null) {
                    $command[] = in_array('--upgrade', $command)
                        ? $package->name
                        : $package->getInstallSpec();
                }
            }
        }
        // When the user command already carries --index-url or a local path we intentionally
        // do NOT append other stored packages: mixing them into the same uv invocation would
        // route all of them through the custom index, breaking packages that live on PyPI.

        $result = $this->python_service->executePipCommand($this->project, $command);

        $this->output->displayMessage($result['output']);

        // Packages with custom sources (index_url / path) are always installed one-by-one so
        // that each uv call can carry the right --index-url for that package.
        $custom_refreshed = [];
        if (!$has_custom_source && !in_array('--no-deps', $command)) {
            foreach ($this->project->getPackages() as $package) {
                if (!$this->commandIncludesPackage($command, $package)
                    && ($package->index_url !== null || $package->path !== null)) {
                    $is_successful = $this->installPackage($package);
                    if ($is_successful) {
                        $custom_refreshed[] = $package;
                    } else {
                        $this->project->removePackage($package);
                    }
                }
            }
        }

        // Parse the main-command output and persist newly installed packages.
        $packages_to_refresh = $custom_refreshed;
        $existing_packages   = $this->project->getPackages();

        // A bare path install doesn't carry the resolved name in the command, so read
        // the primary distribution's name from the path to match it in the output. We
        // keep that name (needed for later uninstall and PHP-doc generation) alongside
        // the path (needed to reinstall from source).
        $path_primary = $command_path !== null
            ? $this->readPackageNameFromPath($command_path)
            : null;
        $path_primary = $path_primary !== null ? $this->normalizePackageName($path_primary) : null;
        $path_primary_saved = false;

        foreach ($this->parseAddedPackages($result['output']) as $package) {
            $is_requested    = $this->commandIncludesPackage($command, $package);
            $is_path_primary = $path_primary !== null
                && $this->normalizePackageName($package->name) === $path_primary;
            $is_tracked      = $this->project->isAdded($package);

            // Persist only packages the user actually asked for: named in the command,
            // the primary of a path install, or already tracked. Dependencies pulled in
            // automatically are installed but never written to composer.json.
            if (!$is_requested && !$is_path_primary && !$is_tracked) {
                continue;
            }

            // Preserve index_url / path for packages that are already tracked.
            if (isset($existing_packages[$package->getKey()])) {
                $existing           = $existing_packages[$package->getKey()];
                $package->index_url = $existing->index_url;
                $package->path      = $existing->path;
            } elseif ($command_index_url !== null && $is_requested) {
                $package->index_url = $command_index_url;
            }

            // Record the path on the path install's primary package (keeping its name and
            // version) so it reinstalls from source; dependencies are skipped above.
            if ($is_path_primary) {
                if ($package->path === null) {
                    $package->path = $command_path;
                }
                $path_primary_saved = true;
            }

            $this->project->addPackage($package);
            $packages_to_refresh[] = $package;
        }

        // Fallback: a path install whose name we couldn't resolve is still tracked by its
        // path alone so it reinstalls from source (uninstall / doc generation, which need
        // the name, won't apply to it).
        if ($command_path !== null && !$path_primary_saved && ($result['code'] ?? 1) === 0) {
            $path_package = new Package(path: $command_path);
            $this->project->addPackage($path_package);
            $packages_to_refresh[] = $path_package;
        }

        $this->saveProject();

        $this->refreshPhpDocsForAllPackages();

        //@TODO think about it
        /*if (!empty($packages_to_refresh) || $this->is_new_environment) {
            $this->php_docs->refreshPhpDocs($packages_to_refresh, $this->is_new_environment);
        } elseif ($this->isPhpDocsMissing()) {
            $this->refreshPhpDocsForAllPackages();
        }*/
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

    /**
     * Extract the value of a named flag from a command array.
     * Handles both "--flag value" and "--flag=value" forms.
     */
    private function extractOptionValue(array $command, string $option): ?string
    {
        foreach ($command as $i => $part) {
            if ($part === $option && isset($command[$i + 1])) {
                return $command[$i + 1];
            }
            if (str_starts_with($part, $option . '=')) {
                return substr($part, strlen($option) + 1);
            }
        }
        return null;
    }

    /**
     * Return the first local filesystem path found among the command arguments,
     * resolved to an absolute path, or null if the command contains no path args.
     */
    private function extractPathFromCommand(array $command): ?string
    {
        // These flags consume the next token (their value), so we skip that token.
        $flags_with_value = ['--index-url', '-i', '--extra-index-url', '--find-links', '-f', '--trusted-host'];
        $skip_next = false;

        foreach ($command as $part) {
            if ($skip_next) {
                $skip_next = false;
                continue;
            }
            if (in_array($part, $flags_with_value)) {
                $skip_next = true;
                continue;
            }
            if (str_starts_with($part, '-')) {
                continue;
            }
            if (in_array($part, ['install', 'uninstall', 'list', 'show', 'tree', 'check'])) {
                continue;
            }

            // Looks like a local path
            if (str_starts_with($part, '/')
                || str_starts_with($part, './')
                || str_starts_with($part, '../')
                || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:[\/\\\\]/', $part))
            ) {
                return realpath($part) ?: $part;
            }
        }

        return null;
    }

    /**
     * Best-effort read of a distribution's name from a local install source (a project
     * directory or a wheel/sdist archive), so a "uv pip install ./pkg" can be matched to
     * the package it produced. Returns null when the name can't be determined.
     */
    private function readPackageNameFromPath(string $path): ?string
    {
        // Wheel / sdist archive: the name is everything before the "-<version>" segment,
        // e.g. requests-2.31.0-py3-none-any.whl or scikit_learn-1.4.0.tar.gz. The name
        // itself may contain dashes, so anchor on the first "-" that starts the version.
        $base = basename($path);
        if (preg_match('/^(.+?)-\d.*\.(whl|tar\.gz|tgz|zip)$/', $base, $m)) {
            return $m[1];
        }

        if (!is_dir($path)) {
            return null;
        }

        // PEP 621 / Poetry: the top-level `name = "..."` key in pyproject.toml.
        $pyproject = $path . DIRECTORY_SEPARATOR . 'pyproject.toml';
        if (is_file($pyproject)
            && preg_match('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', (string) file_get_contents($pyproject), $m)) {
            return $m[1];
        }

        // setup.cfg: [metadata] name = ...
        $setup_cfg = $path . DIRECTORY_SEPARATOR . 'setup.cfg';
        if (is_file($setup_cfg)) {
            $ini = @parse_ini_file($setup_cfg, true);
            if (!empty($ini['metadata']['name'])) {
                return (string) $ini['metadata']['name'];
            }
        }

        // Built metadata: *.egg-info/PKG-INFO or a top-level PKG-INFO (sdist), "Name: ...".
        $pkg_infos = array_merge(
            glob($path . DIRECTORY_SEPARATOR . '*.egg-info' . DIRECTORY_SEPARATOR . 'PKG-INFO') ?: [],
            is_file($path . DIRECTORY_SEPARATOR . 'PKG-INFO') ? [$path . DIRECTORY_SEPARATOR . 'PKG-INFO'] : []
        );
        foreach ($pkg_infos as $pkg_info) {
            if (preg_match('/^Name:\s*(.+)$/m', (string) file_get_contents($pkg_info), $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * Normalise a distribution name for comparison per PEP 503: lowercase, with runs of
     * "-", "_" and "." collapsed to a single "-" (so "Foo_Bar" == "foo-bar").
     */
    private function normalizePackageName(string $name): string
    {
        return strtolower((string) preg_replace('/[-_.]+/', '-', trim($name)));
    }

    public function commandIncludesPackage(array $command, Package $package): bool
    {
        // Path-only packages have no name to match against the command.
        if ($package->name === null) {
            return false;
        }
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

    private function parsePackagesFromOutput(string $output, string $sign): array
    {
        preg_match_all('/^\s*' . preg_quote($sign, '/') . '\s+(.+)==(.+)$/m', $output, $matches, PREG_SET_ORDER);
        return array_map(fn($m) => new Package(trim($m[1]), new PackageVersion(trim($m[2]))), $matches);
    }

    public function parseAddedPackages(string $output): array
    {
        return $this->parsePackagesFromOutput($output, '+');
    }

    public function parseRemovedPackages(string $output): array
    {
        return $this->parsePackagesFromOutput($output, '-');
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
        $this->output->displayMessage("$icon \"{$package->getLabel()}\" $status");

        return $is_successful;
    }

    private function uninstallPackage(Package $package): void
    {
        $is_successful = $this->python_service->uninstallPackage($package, $this->project);

        $status = $is_successful ? "successfully uninstalled" : "is not installed";
        $icon = $is_successful ? "☑️" : "ℹ️";
        $this->output->displayMessage("$icon \"{$package->getLabel()}\" $status");
    }

    private function updatePackage(Package $package): bool
    {
        [$is_successful, $is_performed, $message] = $this->python_service->updatePackage($package, $this->project);

        $status = $is_successful ? ($is_performed ? "successfully updated to a new version" : "is already the newest version") : "was not updated. $message";
        $icon = $is_successful ? ($is_performed ? "⬆️" : "ℹ️") : "❌";
        $this->output->displayMessage("$icon \"{$package->getLabel()}\" $status");

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
                'is_root' => true,
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

    private function isPhpDocsMissing(): bool
    {
        $py_dir = $this->dir . DIRECTORY_SEPARATOR . 'py';
        if (!is_dir($py_dir)) return true;
        $entries = array_diff(scandir($py_dir), ['.', '..']);
        return empty($entries);
    }

    private function refreshPhpDocsForAllPackages(): void
    {
        $this->php_docs->refreshPhpDocs($this->project->getPackages(), true);
    }
}
