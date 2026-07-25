<?php

namespace Python_In_PHP\Plugin\Python;

use Composer\Composer;
use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\PackageVersion;
use Python_In_PHP\Plugin\Python\Entities\Project;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Python_In_PHP\Plugin\Python\Services\PythonLockFileService;
use Python_In_PHP\Plugin\Python\Services\UvPythonEnvironmentService;
use Python_In_PHP\Plugin\Python\Services\UvService;
use Python_In_PHP\Plugin\Utils;

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
    private PythonLockFileService $lock_service;

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

        $this->lock_service = new PythonLockFileService(dirname($this->composer->getConfig()->get('vendor-dir')), $this->output);

        $this->project = Project::loadFromComposerExtras($this->getAllComposerExtras());
        $this->applyLockFile();

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
        elseif ($command[0] == 'uninstall') {
            $this->handleUninstall($command);
        }
        else {
            $this->handleOthers($command);
        }
    }

    public function handleInstall(array $command = ['install'])
    {
        // The command the user actually typed, before stored packages are appended below.
        $user_command = $command;

        $command_index_url = $this->extractOptionValue($command, '--index-url');
        $command_path      = $this->extractPathFromCommand($command);
        $has_custom_source = $command_index_url !== null || $command_path !== null;

        if (!$has_custom_source && !in_array('--no-deps', $command)) {
            // Append stored packages without a custom source to reinstall everything in one uv call
            foreach ($this->project->getPackages() as $package) {
                if (!$this->commandIncludesPackage($command, $package)
                    && $package->index_url === null && $package->path === null) {
                    // --upgrade resolves anew within the constraint instead of reinstalling the pin
                    $command[] = in_array('--upgrade', $command)
                        ? $package->getNameWithExtras() . $package->version->convertToPip()
                        : $package->getInstallSpec();
                }
            }
        }
        // With a custom --index-url/path, stored packages are not appended: they would all go through the custom index

        $result = $this->python_service->executePipCommand($this->project, $command);

        $this->output->displayMessage($result['output']);

        // Custom-source packages are installed one-by-one, each with its own --index-url
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

        $packages_to_refresh = array_merge(
            $custom_refreshed,
            $this->persistInstalledPackages($command, $result, $command_index_url, $command_path, $user_command)
        );

        $this->captureMissingPins();

        $this->saveProject();

        $this->refreshPhpDocsForAllPackages();
    }

    /** Parses the install output, persists newly installed packages and returns them. */
    private function persistInstalledPackages(array $command, array $result, ?string $command_index_url, ?string $command_path, array $user_command = []): array
    {
        $packages_to_refresh = [];
        $existing_packages   = $this->project->getPackages();

        // An auto-detected GPU backend is machine-specific, so its "+cu126" segment must not be persisted
        $backend_auto = $command_index_url === null
            && UvService::resolveTorchBackend($user_command ?: $command) === 'auto';

        // For path installs, resolve the distribution name to match it in the output
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

            // Auto-pulled dependencies are installed but never written to composer.json
            if (!$is_requested && !$is_path_primary && !$is_tracked) {
                continue;
            }

            // Keep source config and ownership; only an explicit request promotes an included package to root
            if (isset($existing_packages[$package->getKey()])) {
                $existing           = $existing_packages[$package->getKey()];
                $package->index_url = $existing->index_url;
                $package->path      = $existing->path;
                $package->extras    = $existing->extras;
                $package->from_included_package = $existing->from_included_package && !$is_requested;
            } elseif ($command_index_url !== null && $is_requested) {
                $package->index_url = $command_index_url;
            }

            // Extras requested by the user ("somepackage[geoip]") replace the stored ones
            $requested_extras = $this->extractRequestedExtras($user_command ?: $command, $package);
            if ($requested_extras !== null) {
                $package->extras = $requested_extras;
            }

            // Keep the path so the package reinstalls from source
            if ($is_path_primary) {
                if ($package->path === null) {
                    $package->path = $command_path;
                }
                $path_primary_saved = true;
            }

            // The exact installed version becomes the lock pin; the auto-detected GPU backend
            // segment is machine-specific and dropped unless the source pins it
            $exact = $package->version->toString();
            if ($backend_auto && $package->index_url === null && $package->path === null
                && !$this->commandPinsLocalVersion($user_command ?: $command, $package)) {
                $exact = $this->stripLocalVersion($exact);
            }
            $package->locked_version = $exact;
            $package->version = $this->resolveConstraint($package, $existing_packages[$package->getKey()] ?? null, $user_command ?: $command, $exact);

            $this->project->addPackage($package);
            $packages_to_refresh[] = $package;
        }

        // A path install with an unresolved name is still tracked by its path alone
        if ($command_path !== null && !$path_primary_saved && ($result['code'] ?? 1) === 0) {
            $path_package = new Package(path: $command_path);
            $this->project->addPackage($path_package);
            $packages_to_refresh[] = $path_package;
        }

        // Extras/specifiers requested for an already-satisfied package must be persisted
        // too, even though uv reported no install for it
        if ($user_command !== [] && ($result['code'] ?? 1) === 0) {
            $is_upgrade = in_array('--upgrade', $user_command);
            foreach ($this->project->getPackages() as $package) {
                $requested_extras = $this->extractRequestedExtras($user_command, $package);
                if ($requested_extras !== null) {
                    $package->extras = $requested_extras;
                }
                $specifier = $is_upgrade ? null : $this->extractRequestedConstraint($user_command, $package);
                if ($specifier !== null) {
                    $package->version = new PackageVersion($specifier);
                }
                // A requested package whose kept pin no longer fits the constraint widens it
                if ($package->locked_version !== null && !$package->satisfiesConstraint()
                    && $this->commandIncludesPackage($user_command, $package)) {
                    $package->version = $this->approximateConstraint($package->locked_version);
                }
            }
        }

        return $packages_to_refresh;
    }

    /** The composer.json constraint for a persisted package: explicit specifier > kept constraint > caret of the pin. */
    private function resolveConstraint(Package $package, ?Package $existing, array $user_command, string $exact): PackageVersion
    {
        // With --upgrade a specifier is a one-time target for uv, not a constraint to persist
        $specifier = in_array('--upgrade', $user_command)
            ? null
            : $this->extractRequestedConstraint($user_command, $package);
        if ($specifier !== null) {
            return new PackageVersion($specifier);
        }
        if ($existing !== null) {
            // An explicit bare-name request may move the version outside the old constraint, widening it
            $package->version = $existing->version;
            $is_widened = !$package->satisfiesConstraint() && $this->commandIncludesPackage($user_command, $package);
            if (!$is_widened) {
                return $existing->version;
            }
        }
        return $this->approximateConstraint($exact);
    }

    /** Caret constraint for a plain numeric version, an exact pin otherwise (pre/post-releases). */
    private function approximateConstraint(string $exact): PackageVersion
    {
        $public = $this->stripLocalVersion($exact);
        return preg_match('/^\d+(?:\.\d+){0,2}$/', $public)
            ? new PackageVersion('^' . $public)
            : new PackageVersion('==' . $public);
    }

    /** The version specifier the user typed for this package (e.g. "==2.31.0", ">=2.0"), or null. */
    private function extractRequestedConstraint(array $command, Package $package): ?string
    {
        if ($package->name === null) {
            return null;
        }
        foreach ($command as $part) {
            $part = trim((string) $part, "\"'");
            if (!preg_match('/^([A-Za-z0-9._-]+)(?:\[[^\]]*\])?((?:===|==|~=|!=|>=|<=|>|<).*)$/', $part, $m)) {
                continue;
            }
            if ($this->normalizePackageName($m[1]) === $this->normalizePackageName($package->name)) {
                return trim($m[2]);
            }
        }
        return null;
    }

    /** The extras the user typed for this package (e.g. ["socks"] from "requests[socks]"), or null. */
    private function extractRequestedExtras(array $command, Package $package): ?array
    {
        if ($package->name === null) {
            return null;
        }
        foreach ($command as $part) {
            $part = trim((string) $part, "\"'");
            if (!preg_match('/^([A-Za-z0-9._-]+)\[([^\]]*)\]/', $part, $m)) {
                continue;
            }
            if ($this->normalizePackageName($m[1]) !== $this->normalizePackageName($package->name)) {
                continue;
            }
            $extras = array_values(array_filter(array_map('trim', explode(',', $m[2]))));
            return $extras ?: null;
        }
        return null;
    }

    /** Loads exact pins from python-in-php.lock and drops pins that no longer fit their constraint. */
    private function applyLockFile(): void
    {
        $orphans = $this->project->applyLockData($this->lock_service->read());
        foreach ($orphans as $name) {
            $this->output->verboseMessage("Dropping the stale lock pin for \"$name\": the package is no longer declared in composer.json");
        }
        foreach ($this->project->getPackages() as $package) {
            if (!$package->satisfiesConstraint()) {
                $this->output->displayMessage("⚠️ The locked version {$package->locked_version} of \"{$package->getLabel()}\" does not satisfy the constraint \"{$package->version->toString()}\", so it will be resolved again");
                $package->locked_version = null;
            }
        }
    }

    /** Fills lock pins for tracked packages already present in the environment, so uv reported no install for them. */
    private function captureMissingPins(): void
    {
        $missing = array_filter(
            $this->project->getPackages(),
            fn($package) => $package->name !== null && $package->locked_version === null
        );
        if ($missing === []) {
            return;
        }

        $result = $this->python_service->executePipCommand($this->project, ['freeze']);
        if ($result['code'] !== 0) {
            return;
        }

        $installed = [];
        foreach (explode("\n", $result['output']) as $line) {
            if (preg_match('/^([A-Za-z0-9._-]+)==(.+)$/', trim($line), $m)) {
                $installed[$this->normalizePackageName($m[1])] = trim($m[2]);
            }
        }

        foreach ($missing as $package) {
            $version = $installed[$this->normalizePackageName($package->name)] ?? null;
            if ($version === null) {
                continue;
            }
            // The machine-specific GPU segment is only kept when the source or the constraint pins it
            $keep_local = $package->index_url !== null || $package->path !== null
                || str_contains($package->version->toString(), '+');
            $package->locked_version = $keep_local ? $version : $this->stripLocalVersion($version);
        }
    }

    /** Reads the exact installed version of the package from uv's output into its lock pin. */
    private function captureLockedVersion(Package $package, string $output): void
    {
        if ($package->name === null) {
            return;
        }
        foreach ($this->parseAddedPackages($output) as $added) {
            if ($this->normalizePackageName($added->name) === $this->normalizePackageName($package->name)) {
                $package->locked_version = $added->version->toString();
                return;
            }
        }
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

    /** PEP 503 name normalisation (so "Foo_Bar" == "foo-bar"). */
    private function normalizePackageName(string $name): string
    {
        return Utils::normalizePackageName($name);
    }

    /** Drops the PEP 440 local version segment ("2.7.0+rocm6.3" -> "2.7.0"). */
    private function stripLocalVersion(string $version): string
    {
        $plus = strpos($version, '+');
        return $plus === false ? $version : substr($version, 0, $plus);
    }

    /** Whether the command pins this package to a specific local version (e.g. "torch==2.7.0+cu118"). */
    private function commandPinsLocalVersion(array $command, Package $package): bool
    {
        if ($package->name === null) {
            return false;
        }
        foreach ($command as $part) {
            if (str_contains((string) $part, '+') && $this->commandIncludesPackage([$part], $package)) {
                return true;
            }
        }
        return false;
    }

    public function commandIncludesPackage(array $command, Package $package): bool
    {
        // Path-only packages have no name to match against the command.
        if ($package->name === null) {
            return false;
        }
        $target = $this->normalizePackageName((string) $this->requirementName($package->name));
        foreach ($command as $part) {
            $part = trim((string) $part, "\"'");
            if ($part === '' || str_starts_with($part, '-')) {
                continue;
            }
            $name = $this->requirementName($part);
            if ($name !== null && $this->normalizePackageName($name) === $target) {
                return true;
            }
        }
        return false;
    }

    /** The distribution name at the start of a pip requirement ("pkg[extra]==1.0" -> "pkg"), or null. */
    private function requirementName(string $part): ?string
    {
        return preg_match('/^\s*([A-Za-z0-9][A-Za-z0-9._-]*)/', $part, $m) ? $m[1] : null;
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

    public function run(array $arguments): int
    {
        return $this->python_service->runPython($arguments, $this->project);
    }

    public function getPythonVersion(): string
    {
        return $this->project->getPythonVersion();
    }

    private function installPackage(Package $package): bool
    {
        [$is_successful, $message, $uv_output] = $this->python_service->installPackage($package, $this->project);
        if ($is_successful) {
            $this->captureLockedVersion($package, $uv_output);
        }

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
        [$is_successful, $is_performed, $message, $uv_output] = $this->python_service->updatePackage($package, $this->project);
        if ($is_successful && $is_performed) {
            $this->captureLockedVersion($package, $uv_output);
        }

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
        $changed = $this->project->saveInComposerJson($composer_json_path);
        // The extra section takes part in composer.lock's content-hash, so keep the hash fresh
        if ($changed) {
            $this->lock_service->patchComposerLockHash();
        }
        $this->lock_service->write($this->project->toLockArray());
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
