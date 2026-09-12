<?php

namespace Python_In_PHP\Plugin\Python\Services;

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Exceptions\UnsupportedOS;
use Python_In_PHP\Plugin\Python\Traits\CommandLineTrait;
use Python_In_PHP\Plugin\Utils;

class UvPythonEnvironmentService
{
    use CommandLineTrait;

    public $uv_version = '0.11.29';

    /** uv found on PATH, used only when the download into vendor/bin fails */
    private ?string $uv_from_path = null;

    public function __construct(
        private string $dir,
        private string $bin_dir,
        private OutputService $output
    ){
        // Defaults only: a user-set value (Docker cache mount, system interpreter) wins
        if (!getenv('UV_PYTHON_INSTALL_DIR')) {
            putenv('UV_PYTHON_INSTALL_DIR=' . $this->bin_dir . DIRECTORY_SEPARATOR . 'python');
        }
        if (!getenv('UV_PYTHON_PREFERENCE')) {
            putenv('UV_PYTHON_PREFERENCE=only-managed');
        }
    }

    public function installUvIfMissing(): void
    {
        $external = getenv('UV_BIN');
        if ($external) {
            if (!is_file($external)) {
                throw new \RuntimeException("UV_BIN points to a missing file: $external");
            }
            if ($this->isUvOutdated($external)) {
                $this->output->displayMessage("Warning: UV_BIN uv is older than v{$this->uv_version}; --torch-backend=auto may miss newer GPU backends.");
            }
            return;
        }

        $uv_bin = $this->getUvBinPath();
        if (file_exists($uv_bin) && !$this->isUvOutdated($uv_bin)) return;

        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');
        $url = $this->getUvDownloadLink($os, $arch);
        $target = $this->bin_dir . DIRECTORY_SEPARATOR . (str_ends_with($url, '.zip') ? 'uv.zip' : 'uv.tar.gz');

        if (!is_dir($this->bin_dir)) {
            mkdir($this->bin_dir, 0777, true);
        }

        $ok = false;
        if (!getenv('UV_OFFLINE')) {
            $this->output->displayMessage("Downloading uv v{$this->uv_version}...");

            set_error_handler(static fn() => true);
            try {
                $ok = copy($url, $target);
            } finally {
                restore_error_handler();
            }
        }

        if (!$ok || !file_exists($target)) {
            // No network (or UV_OFFLINE): fall back to a uv already installed on PATH
            $this->uv_from_path = $this->findUvOnPath();
            if ($this->uv_from_path !== null) {
                $this->output->displayMessage("Could not download uv, using {$this->uv_from_path} from PATH.");
                return;
            }
            throw new \RuntimeException(
                (getenv('UV_OFFLINE') ? "uv is not installed and UV_OFFLINE is set.\n" : "Failed to download uv from $url.\n") .
                "Install uv manually, then re-run composer install:\n" .
                "  macOS/Linux: curl -Ls https://astral.sh/uv/install.sh | sh\n" .
                "  Windows:     winget install astral-sh.uv\n" .
                "and point UV_BIN at the binary or make sure it is on PATH."
            );
        }

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

    /** A binary older than $uv_version is re-downloaded: GPU support of --torch-backend=auto depends on the uv release. */
    private function isUvOutdated(string $uv_bin): bool
    {
        $result = $this->runCommand(escapeshellarg($uv_bin) . ' --version');
        $installed_version = $result['code'] === 0 ? $this->parseUvVersion($result['output']) : null;

        if ($installed_version === null) {
            return false;
        }

        return version_compare($installed_version, $this->uv_version, '<');
    }

    public function parseUvVersion(string $version_output): ?string
    {
        return preg_match('/\buv\s+(\d+\.\d+\.\d+)/', $version_output, $matches) ? $matches[1] : null;
    }

    public function createEnvironment(string $python_version): void
    {
        $uv_bin = escapeshellarg($this->getUvBinPath());
        $env_path = $this->getEnvDir($python_version);
        $escaped_env_path = escapeshellarg($env_path);

        // --relocatable keeps the venv's entry points path-independent (Docker multi-stage copies)
        $cmd = "$uv_bin venv --relocatable --python $python_version $escaped_env_path";
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

    public function restoreSymlinkIfMissing(string $python_version): void
    {
        $python_bin_path = $this->getPythonBinPath();
        if (file_exists($python_bin_path)) return;

        $env_path = $this->getEnvDir($python_version);
        if (!is_dir($env_path)) return;

        $bin_subdir = PHP_OS_FAMILY === 'Windows' ? 'Scripts' : 'bin';
        $this->createSymlink(
            dirname($python_bin_path),
            $env_path . DIRECTORY_SEPARATOR . $bin_subdir
        );
    }

    /** UV_BIN if set, else the uv downloaded into vendor/bin (or one found on PATH when that failed) */
    public function getUvBinPath(): string
    {
        $external = getenv('UV_BIN');
        if ($external) {
            return $external;
        }
        if ($this->uv_from_path !== null) {
            return $this->uv_from_path;
        }
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        return $this->bin_dir . DIRECTORY_SEPARATOR . 'uv' . $ext;
    }

    private function findUvOnPath(): ?string
    {
        $result = $this->runCommand(PHP_OS_FAMILY === 'Windows' ? 'where uv' : 'command -v uv');
        $path = trim(strtok($result['output'], "\r\n") ?: '');
        return $result['code'] === 0 && $path !== '' && is_file($path) ? $path : null;
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
        return $this->bin_dir . DIRECTORY_SEPARATOR . 'envs' . DIRECTORY_SEPARATOR . $python_version;
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

    protected function createSymlink(string $link, string $target): void
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
        Utils::deleteFolder($this->bin_dir . DIRECTORY_SEPARATOR . 'envs');
    }
}
