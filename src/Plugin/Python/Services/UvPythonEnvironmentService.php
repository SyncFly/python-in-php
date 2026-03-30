<?php

namespace Python_In_PHP\Plugin\Python\Services;

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Exceptions\UnsupportedOS;
use Python_In_PHP\Plugin\Python\Traits\CommandLineTrait;
use Python_In_PHP\Plugin\Utils;

class UvPythonEnvironmentService
{
    use CommandLineTrait;

    public $uv_version = '0.9.26';

    public function __construct(
        private string $dir,
        private string $bin_dir,
        private OutputService $output
    ){}

    public function installUvIfMissing(): void
    {
        $uv_bin = $this->getUvBinPath();
        if (file_exists($uv_bin)) return;

        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');
        $url = $this->getUvDownloadLink($os, $arch);
        $target = $this->bin_dir . DIRECTORY_SEPARATOR . (str_ends_with($url, '.zip') ? 'uv.zip' : 'uv.tar.gz');

        if (!is_dir($this->bin_dir)) {
            mkdir($this->bin_dir, 0777, true);
        }

        $this->output->displayMessage("Downloading uv...");
        copy($url, $target);

        if ($os === 'Windows') {
            // uv provides .zip for Windows
            $zip = new \ZipArchive;
            if ($zip->open($target) === TRUE) {
                $zip->extractTo($this->bin_dir);
                $zip->close();
            }
        } else {
            shell_exec("tar -xzf " . escapeshellarg($target) . " --strip-components=1 -C " . escapeshellarg($this->bin_dir));
            chmod($uv_bin, 0755);
        }

        unlink($target);
    }

    public function createEnvironment(string $python_version): void
    {
        $uv_bin = escapeshellarg($this->getUvBinPath());
        $env_path = $this->getEnvDir($python_version);
        $escaped_env_path = escapeshellarg($env_path);

        // Create venv with the required Python version
        $cmd = "$uv_bin venv --python $python_version $escaped_env_path";
        $this->runCommand($cmd);

        // Create a symlink for PythonManager
        // On Unix systems path: venv/bin/python, on Windows: venv/Scripts/python.exe
        $bin_subdir = PHP_OS_FAMILY === 'Windows' ? 'Scripts' : 'bin';
        $python_executable = $env_path . DIRECTORY_SEPARATOR . $bin_subdir . DIRECTORY_SEPARATOR . 'python' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        $this->createSymlink(dirname($this->getPythonBinPath()), dirname($python_executable));
    }

    public function isEnvironmentCreated(string $version): bool
    {
        return is_dir($this->getEnvDir($version));
    }

    public function createEnvironmentIfMissing(string $version): bool
    {
        if ($this->isEnvironmentCreated($version)) return false;
        $this->createEnvironment($version);
        return true;
    }

    public function getUvBinPath(): string
    {
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        return $this->bin_dir . DIRECTORY_SEPARATOR . 'uv' . $ext;
    }

    public function getPythonBinPath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'python_bin' . DIRECTORY_SEPARATOR . 'python' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    public function getPythonBinPathReal(): string
    {
        return realpath($this->dir . DIRECTORY_SEPARATOR . 'python_bin') . DIRECTORY_SEPARATOR . 'python' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    public function getEnvDir(string $python_version): string
    {
        return $this->bin_dir . DIRECTORY_SEPARATOR . 'uv_envs' . DIRECTORY_SEPARATOR . $python_version;
    }

    private function getUvDownloadLink(string $os, string $arch): string
    {
        $arch = strtolower($arch);
        $base = "https://github.com/astral-sh/uv/releases/download/{$this->uv_version}/";

        $uvArch = match ($arch) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            'i386', 'i686' => 'i686',
            default => $arch,
        };

        $fileName = match ($os) {
            'Windows' => match ($uvArch) {
                'x86_64' => 'uv-x86_64-pc-windows-msvc.zip',
                'aarch64' => 'uv-aarch64-pc-windows-msvc.zip',
                'i686' => 'uv-i686-pc-windows-msvc.zip',
                default => null,
            },
            'Linux' => match ($uvArch) {
                'x86_64' => 'uv-x86_64-unknown-linux-musl.tar.gz',
                'aarch64' => 'uv-aarch64-unknown-linux-musl.tar.gz',
                'i686' => 'uv-i686-unknown-linux-musl.tar.gz',
                default => null,
            },
            'Darwin' => match ($uvArch) {
                'x86_64' => 'uv-x86_64-apple-darwin.tar.gz',
                'aarch64' => 'uv-aarch64-apple-darwin.tar.gz',
                default => null,
            },
            default => null,
        };

        if ($fileName === null) {
            throw new UnsupportedOS("OS $os with architecture $arch is not supported for uv.");
        }

        return $base . $fileName;
    }

    private function createSymlink(string $link, string $target): void
    {
        $linkParent = dirname($link);
        if (!is_dir($linkParent)) {
            mkdir($linkParent, 0777, true);
        }

        // Resolve target to absolute path (if possible)
        $targetReal = realpath($target);
        if ($targetReal !== false) {
            $target = $targetReal;
        }

        if (!file_exists($target)) {
            throw new \RuntimeException("Target $target does not exist.");
        }

        // Remove the existing link (symlink/file/folder)
        if (is_link($link) || is_file($link)) {
            unlink($link);
        } elseif (is_dir($link)) {
            // if this is a real directory (not a symlink), rmdir will only work if it's empty
            rmdir($link);
        }

        if (!symlink($target, $link)) {
            $err = error_get_last();
            throw new \RuntimeException('Failed to create symlink: ' . ($err['message'] ?? 'unknown error'));
        }
    }

    public function deleteAllEnvironments(): void
    {
        Utils::deleteFolder($this->bin_dir . DIRECTORY_SEPARATOR . 'uv_envs');
    }
}
