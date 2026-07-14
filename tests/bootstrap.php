<?php

$libraryDir = dirname(__DIR__);
$fixtureDir = $libraryDir . '/fixtures/project';

// Install fixture if py stubs are not yet generated (json is stdlib — reliable indicator)
//if (!file_exists($fixtureDir . '/vendor/syncfly/python-in-php/py/json.php')) {
//    echo "Setting up test fixture (first run, takes a few minutes)...\n";
    $result = 0;
    passthru(
        'COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-ansi --no-dev -d ' . escapeshellarg($fixtureDir),
        $result
    );
    if ($result !== 0) {
        throw new RuntimeException(
            "Fixture setup failed (exit code $result).\n" .
            'Run manually: COMPOSER_ALLOW_SUPERUSER=1 composer install -d tests/fixtures/project'
        );
    }
//}

// Composer may install the library into the fixture as a copy or a symlink,
// depending on the local Composer/path repository behavior. Sync PHP files
// when it is a copy so tests always run against current library code.
$srcDir = $libraryDir . '/src';
$vendorPackageDir = $fixtureDir . '/vendor/syncfly/python-in-php';
$vendorSrcDir = $vendorPackageDir . '/src';
if (!is_link($vendorPackageDir)) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $dest = $vendorSrcDir . substr($file->getPathname(), strlen($srcDir));
        if (!file_exists($dest) || filemtime($file->getPathname()) > filemtime($dest)) {
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), recursive: true);
            }
            copy($file->getPathname(), $dest);
        }
    }
}

// Fixture autoloader: provides py\* (generated stubs), Python_In_PHP\* (now synced above),
// WebSocket deps, PHPUnit, etc. We must NOT also load the main library's vendor/autoload.php
// as that would duplicate PHPUnit class definitions and cause fatal errors.
require $fixtureDir . '/vendor/autoload.php';

// Register Tests\ namespace (not covered by any autoloader above).
spl_autoload_register(function (string $class) use ($libraryDir): void {
    if (!str_starts_with($class, 'Tests\\')) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen('Tests\\')));
    $file = $libraryDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
