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
        $package_spec = $package->name . $package->version->convertToPip();
        $result = $this->executeUvCommand($project, 'pip', ['install', $package_spec]);
        
        $is_successful = $result['code'] === 0;
        return [$is_successful, $is_successful ? "Installed $package_spec" : "Failed to install: " . $result['output']];
    }

    public function uninstallPackage(Package $package, Project $project): bool
    {
        $result = $this->executeUvCommand($project, 'pip', ['uninstall', $package->name]);
        return $result['code'] === 0;
    }

    public function updatePackage(Package $package, Project $project): array
    {
        $package_spec = $package->name . $package->version->convertToPip();
        $result = $this->executeUvCommand($project, 'pip', ['install', '--upgrade', $package_spec]);
        
        $is_successful = $result['code'] === 0;
        $is_performed = !str_contains($result['output'], 'already satisfied');
        
        return [$is_successful, $is_performed, $is_successful ? "Updated $package->name" : "Update failed"];
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

    private function executeUvCommand(Project $project, string $method, array $arguments): array
    {
        $uv_bin = $this->python_environment->getUvBinPath();
        $uv_env = $this->python_environment->getEnvDir($project->getPythonVersion()) . '/bin/python';

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
