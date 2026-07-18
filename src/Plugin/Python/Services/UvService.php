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
        return [$is_successful, $is_successful ? "Installed {$package->getLabel()}" : "Failed to install: " . $result['output']];
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
        $args = ['install', '--upgrade', $package->getInstallSpec()];

        if ($package->index_url !== null) {
            $args[] = '--index-url';
            $args[] = $package->index_url;
        }

        $result = $this->executeUvCommand($project, 'pip', $args);

        $is_successful = $result['code'] === 0;
        $is_performed = !str_contains($result['output'], 'already satisfied');

        return [$is_successful, $is_performed, $is_successful ? "Updated {$package->getLabel()}" : "Update failed"];
    }

    public function runPython(array $arguments, Project $project): string
    {
        // uv run automatically picks up the environment if we are in the right directory,
        // or we can pass the path to python directly
        $python_bin = $this->python_environment->getPythonBinPath();
        $python_bin = realpath($python_bin);
        $arguments_string = implode(' ', array_map('escapeshellarg', $arguments));

        $cmd = escapeshellarg($python_bin) . " " . $arguments_string;
        $result = $this->runCommand($cmd);

        return $result['output'];
    }

    /**
     * Pick the PyTorch accelerator for `pip install`:
     *  - Linux/Windows: default uv's --torch-backend to "auto", so uv detects the
     *    GPU (NVIDIA CUDA, AMD ROCm, Intel XPU) and picks the matching wheel index.
     *  - macOS: pass no flag — the regular PyPI wheels already ship Metal/MPS
     *    support, and --torch-backend would needlessly pin the CPU-only index.
     * An explicit --torch-backend from the caller, or the PYTHON_IN_PHP_TORCH_BACKEND
     * environment variable (e.g. "cu128", "rocm7.2", "cpu", or "none" to disable),
     * takes precedence. Non-install subcommands reject the flag and are left as is.
     */
    private function withDefaultTorchBackend(array $arguments, ?string $os_family = null): array
    {
        if (($arguments[0] ?? null) !== 'install') {
            return $arguments;
        }

        foreach ($arguments as $argument) {
            if ($argument === '--torch-backend' || str_starts_with((string) $argument, '--torch-backend=')) {
                return $arguments;
            }
        }

        $backend = getenv('PYTHON_IN_PHP_TORCH_BACKEND');
        if ($backend === false || $backend === '') {
            if (($os_family ?? PHP_OS_FAMILY) === 'Darwin') {
                return $arguments;
            }
            $backend = 'auto';
        }

        if (strtolower($backend) === 'none') {
            return $arguments;
        }

        $arguments[] = '--torch-backend=' . $backend;
        return $arguments;
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
