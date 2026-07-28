<?php

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Entities\Package;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Symfony\Component\Console\Output\BufferedOutput;

// The generation-skip decision: a package is up to date only when its pinned version
// matches the last generated one and its doc files still exist.

function makeDocService(string $dir): PhpDocGeneratorService
{
    $service = (new ReflectionClass(PhpDocGeneratorService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(PhpDocGeneratorService::class, 'dir'))->setValue($service, $dir);
    (new ReflectionProperty(PhpDocGeneratorService::class, 'output'))->setValue($service, new OutputService(new BufferedOutput()));
    return $service;
}

function isUpToDate(PhpDocGeneratorService $service, Package $package, array $modules, array $generated): bool
{
    $method = new ReflectionMethod($service, 'isPackageUpToDate');
    return $method->invoke($service, $package, $modules, $generated);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpdoc-state-' . uniqid();
    mkdir($this->dir . DIRECTORY_SEPARATOR . 'py', recursive: true);
});

afterEach(function () {
    Python_In_PHP\Plugin\Utils::deleteFolder($this->dir);
});

test('a package without a pinned version is never up to date', function () {
    $service = makeDocService($this->dir);
    $package = new Package('numpy');
    expect(isUpToDate($service, $package, [], ['numpy' => '2.4.6']))->toBeFalse();
});

test('a version mismatch or a missing state entry requires regeneration', function () {
    $service = makeDocService($this->dir);
    $package = new Package('numpy', locked_version: '2.4.6');
    expect(isUpToDate($service, $package, [], ['numpy' => '2.4.5']))->toBeFalse();
    expect(isUpToDate($service, $package, [], []))->toBeFalse();
});

test('a matching version with existing docs is up to date', function () {
    $service = makeDocService($this->dir);
    file_put_contents($this->dir . '/py/numpy.php', '<?php');
    $package = new Package('numpy', locked_version: '2.4.6');
    expect(isUpToDate($service, $package, ['numpy'], ['numpy' => '2.4.6']))->toBeTrue();
});

test('a matching version with deleted docs requires regeneration', function () {
    $service = makeDocService($this->dir);
    $package = new Package('numpy', locked_version: '2.4.6');
    expect(isUpToDate($service, $package, ['numpy'], ['numpy' => '2.4.6']))->toBeFalse();
});

test('deliberately skipped modules are not recorded for retry', function () {
    $service = makeDocService($this->dir);
    (new ReflectionProperty(PhpDocGeneratorService::class, 'importErrors'))->setValue($service, [
        'whisperx'       => 'AttributeError: boom',
        'encodings.mbcs' => "Module 'encodings.mbcs' excluded by pattern",
    ]);
    $failed = (new ReflectionMethod($service, 'failedModules'))->invoke($service);
    expect($failed)->toBe(['whisperx']);
});

test('the generation state survives a write-read round-trip', function () {
    $service = makeDocService($this->dir);
    $state = ['python-version' => '3.12', 'builtins' => true, 'packages' => ['numpy' => '2.4.6'], 'failed_modules' => []];
    (new ReflectionMethod($service, 'writeGenerationState'))->invoke($service, $state);
    expect((new ReflectionMethod($service, 'readGenerationState'))->invoke($service))->toBe($state);
});
