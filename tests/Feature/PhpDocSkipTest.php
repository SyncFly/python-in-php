<?php

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Python_In_PHP\Plugin\Utils;
use Symfony\Component\Console\Output\BufferedOutput;

// End-to-end generation skip: an unchanged package is not regenerated, a version bump is.

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpdoc-skip-' . uniqid();
    mkdir($this->dir, recursive: true);
});

afterEach(function () {
    if (isset($this->dir)) Utils::deleteFolder($this->dir);
});

function refreshDocs(string $dir, Package $package): string
{
    $output = new BufferedOutput();
    $service = new PhpDocGeneratorService($dir, new OutputService($output));
    $service->refreshPhpDocs([$package]);
    return $output->fetch();
}

test('an unchanged package is skipped on the next refresh, a version bump regenerates', function () {
    $version = Py::eval("__import__('importlib.metadata', fromlist=['v']).version('idna')");
    $package = new Package('idna', locked_version: $version);

    $first = refreshDocs($this->dir, $package);
    expect($first)->toContain('Generating PHP Docs');
    expect(file_exists($this->dir . '/py/idna.php'))->toBeTrue();

    $second = refreshDocs($this->dir, $package);
    expect($second)->toContain('up to date');
    expect($second)->not->toContain('Generating PHP Docs');

    $package->locked_version = '999.0';
    $third = refreshDocs($this->dir, $package);
    expect($third)->toContain('Generating PHP Docs');
});
