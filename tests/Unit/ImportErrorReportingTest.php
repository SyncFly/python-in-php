<?php

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

// Import-error reporting: expected noise is hidden, real errors are listed compactly,
// and -v shows everything in full.

function reportImportErrors(array $errors, int $verbosity): string
{
    $output = new BufferedOutput($verbosity);
    $service = (new ReflectionClass(PhpDocGeneratorService::class))->newInstanceWithoutConstructor();

    $output_property = new ReflectionProperty(PhpDocGeneratorService::class, 'output');
    $output_property->setValue($service, new OutputService($output));

    $errors_property = new ReflectionProperty(PhpDocGeneratorService::class, 'importErrors');
    $errors_property->setValue($service, $errors);

    $method = new ReflectionMethod($service, 'reportImportErrors');
    $method->setAccessible(true);
    $method->invoke($service);

    return $output->fetch();
}

test('a missing module (ModuleNotFoundError) is not reported', function () {
    $out = reportImportErrors(['winreg' => "ModuleNotFoundError: No module named 'winreg'"], OutputInterface::VERBOSITY_NORMAL);
    expect(trim($out))->toBe('');
});

test('a wrong-OS stub (win32 only) is not reported', function () {
    $out = reportImportErrors(['asyncio.windows_events' => 'ImportError: win32 only'], OutputInterface::VERBOSITY_NORMAL);
    expect(trim($out))->toBe('');
});

test('a real import error is reported compactly by module name with a -v hint', function () {
    $out = reportImportErrors(['some.module' => 'ImportError: cannot import name foo'], OutputInterface::VERBOSITY_NORMAL);
    expect($out)->toContain('some.module');
    expect($out)->toContain('run with -v for details');
    expect($out)->not->toContain('cannot import name foo');
});

test('several real errors are joined with commas', function () {
    $out = reportImportErrors([
        'a.mod'  => 'ImportError: cannot import name foo',
        'b.mod'  => 'ValueError: bad thing',
        'winreg' => "ModuleNotFoundError: No module named 'winreg'",
    ], OutputInterface::VERBOSITY_NORMAL);

    expect($out)->toContain('a.mod, b.mod');
    expect($out)->not->toContain('winreg');
});

test('verbose mode shows every error in full, including the ignorable ones', function () {
    $out = reportImportErrors([
        'winreg'    => "ModuleNotFoundError: No module named 'winreg'",
        'some.mod'  => 'ImportError: cannot import name foo',
    ], OutputInterface::VERBOSITY_VERBOSE);

    expect($out)->toContain("winreg: ModuleNotFoundError: No module named 'winreg'");
    expect($out)->toContain('some.mod: ImportError: cannot import name foo');
});

test('nothing is printed when there are no import errors', function () {
    expect(trim(reportImportErrors([], OutputInterface::VERBOSITY_NORMAL)))->toBe('');
});
