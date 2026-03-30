<?php

namespace Python_In_PHP\Plugin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Utils
{
    public static function deleteFolder(string $dir): bool
    {
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $file) {
                $file->isDir() ? rmdir($file) : unlink($file);
            }

            return rmdir($dir);
        }
        return false;
    }

    public static function deleteFile(string $path): bool
    {
        if (is_file($path)) {
            return unlink($path);
        }
        return false;
    }
}