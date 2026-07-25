<?php

namespace Python_In_PHP\Plugin\Python\Services;

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Entities\Project;
use Python_In_PHP\Plugin\Python\Traits\CommandLineTrait;

class UvService
{
    use CommandLineTrait;

    public function __construct(
        private UvPythonEnvironmentService $python_environment,
        private OutputService $output
    ) {}

    public function installPackage(Package $package, Project $project): array
    {
        $args = ['install', $package->getInstallSpec()];

        if ($package->index_url !== null) {
            $args[] = '--index-url';
            $args[] = $package->index_url;
        }

        $result = $this->executeUvCommand($project, 'pip', $args);

        $is_successful = $result['code'] === 0;
        return [$is_successful, $is_successful ? "Installed {$package->getLabel()}" : "Failed to install: " . $result['output'], $result['output']];
    }

    public function uninstallPackage(Package $package, Project $project): bool
    {
        // uv can only uninstall by distribution name; a path-only package has none.
        if ($package->name === null) {
            return false;
        }
        $result = $this->executeUvCommand($project, 'pip', ['uninstall', $package->name]);
        return $result['code'] === 0;
    }

    public function updatePackage(Package $package, Project $project): array
    {
        // An upgrade resolves anew within the constraint instead of reinstalling the exact pin
        $spec = $package->path !== null ? $package->path : $package->getNameWithExtras() . $package->version->convertToPip();
        $args = ['install', '--upgrade', $spec];

        if ($package->index_url !== null) {
            $args[] = '--index-url';
            $args[] = $package->index_url;
        }

        $result = $this->executeUvCommand($project, 'pip', $args);

        $is_successful = $result['code'] === 0;
        $is_performed = !str_contains($result['output'], 'already satisfied');

        return [$is_successful, $is_performed, $is_successful ? "Updated {$package->getLabel()}" : "Update failed", $result['output']];
    }

    /** Run the venv Python with the given arguments, streaming its output live; returns the exit code. */
    public function runPython(array $arguments, Project $project): int
    {
        // Resolve only the python_bin symlink (to the venv's bin), not the full chain: fully
        // resolving it would land on the base interpreter and lose the venv's site-packages.
        $python_bin = $this->python_environment->getPythonBinPathReal();
        $arguments_string = implode(' ', array_map('escapeshellarg', $arguments));

        $cmd = escapeshellarg($python_bin) . ' ' . $arguments_string;
        passthru($cmd, $exit_code);

        return $exit_code;
    }

    /** Defaults --torch-backend=auto on installs; skipped on macOS (PyPI wheels ship MPS), overridable via PYTHON_IN_PHP_TORCH_BACKEND ("none" disables). */
    private function withDefaultTorchBackend(array $arguments, ?string $os_family = null): array
    {
        $backend = self::resolveTorchBackend($arguments, $os_family);
        if ($backend === null) {
            return $arguments;
        }

        // An explicit flag is already present in that case, so leave the arguments untouched.
        foreach ($arguments as $argument) {
            if ($argument === '--torch-backend' || str_starts_with((string) $argument, '--torch-backend=')) {
                return $arguments;
            }
        }

        $arguments[] = '--torch-backend=' . $backend;
        return $arguments;
    }

    /** The applied --torch-backend value, or null when none applies; "auto" means auto-detected, not user-pinned. */
    public static function resolveTorchBackend(array $arguments, ?string $os_family = null): ?string
    {
        if (($arguments[0] ?? null) !== 'install') {
            return null;
        }

        foreach ($arguments as $i => $argument) {
            if ($argument === '--torch-backend') {
                return $arguments[$i + 1] ?? null;
            }
            if (str_starts_with((string) $argument, '--torch-backend=')) {
                return substr((string) $argument, strlen('--torch-backend='));
            }
        }

        $backend = getenv('PYTHON_IN_PHP_TORCH_BACKEND');
        if ($backend === false || $backend === '') {
            if (($os_family ?? PHP_OS_FAMILY) === 'Darwin') {
                return null;
            }
            return 'auto';
        }

        if (strtolower($backend) === 'none') {
            return null;
        }

        return $backend;
    }

    private function executeUvCommand(Project $project, string $method, array $arguments): array
    {
        $uv_bin = $this->python_environment->getUvBinPath();
        $uv_env = $this->python_environment->getEnvDir($project->getPythonVersion()) . '/bin/python';

        if ($method === 'pip') {
            $arguments = $this->withDefaultTorchBackend($arguments);
        }

        // Specify the path to venv via environment variable or --python flag
        // For uv pip we need to specify the path to python in venv
        $python_path = $this->python_environment->getPythonBinPathReal();

        $arguments_string = implode(' ', array_map('escapeshellarg', $arguments));

        $cmd = sprintf(
            '%s %s %s --python %s',
            escapeshellarg($uv_bin),
            $method,
            $arguments_string,
            escapeshellarg($python_path)
        );

        return $this->runCommand($cmd);
    }

    public function executePipCommand(Project $project, array $arguments): array
    {
        return $this->executeUvCommand($project, 'pip', $arguments);
    }
}
