<?php

namespace Python_In_PHP\Plugin\Python\Services;

use Python_In_PHP\Plugin\OutputService;

class PythonLockFileService
{
    public const LOCK_FILE = 'python-in-php.lock';
    private const README = 'This file locks the Python packages of your project to known versions. Commit it to version control.';

    private string $lock_path;
    private string $composer_json_path;
    private string $composer_lock_path;

    public function __construct(string $project_root, private ?OutputService $output = null)
    {
        $this->lock_path          = $project_root . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $this->composer_json_path = $project_root . DIRECTORY_SEPARATOR . 'composer.json';
        $this->composer_lock_path = $project_root . DIRECTORY_SEPARATOR . 'composer.lock';
    }

    /** Parsed lock data, or null when the file is missing or unreadable. */
    public function read(): ?array
    {
        if (!is_file($this->lock_path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->lock_path), true);
        if (!is_array($data)) {
            $this->output?->displayMessage('⚠️ ' . self::LOCK_FILE . ' is not valid JSON, the file was ignored');
            return null;
        }
        return $data;
    }

    /** Writes the lock data only when it changed; returns whether a write happened. */
    public function write(array $data): bool
    {
        $data = ['_readme' => self::README] + $data;
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (is_file($this->lock_path) && file_get_contents($this->lock_path) === $encoded) {
            return false;
        }
        file_put_contents($this->lock_path, $encoded);
        return true;
    }

    /** Refreshes composer.lock's content-hash after the plugin edited the extra section of composer.json. */
    public function patchComposerLockHash(): void
    {
        if (!is_file($this->composer_lock_path) || !is_file($this->composer_json_path)) {
            return;
        }
        if (!class_exists(\Composer\Package\Locker::class)) {
            return;
        }
        $hash = \Composer\Package\Locker::getContentHash((string) file_get_contents($this->composer_json_path));
        $lock = (string) file_get_contents($this->composer_lock_path);
        // Only the hash value is replaced, keeping the rest of the file byte-identical
        $patched = preg_replace('/("content-hash":\s*")[0-9a-f]{32}(")/', '${1}' . $hash . '$2', $lock, 1);
        if ($patched !== null && $patched !== $lock) {
            file_put_contents($this->composer_lock_path, $patched);
        }
    }
}
