<?php

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

// Import-error reporting: failures in explicitly installed packages are listed by name
// (full errors at -v); everything else is a count only (full errors at -vv); expected
// noise (missing modules, platform-only stubs) is hidden below -v.

function reportImportErrors(array $errors, int $verbosity, array $user_modules = []): string
{
    $output = new BufferedOutput($verbosity);
    $service = (new ReflectionClass(PhpDocGeneratorService::class))->newInstanceWithoutConstructor();

    $output_property = new ReflectionProperty(PhpDocGeneratorService::class, 'output');
    $output_property->setValue($service, new OutputService($output));

    $errors_property = new ReflectionProperty(PhpDocGeneratorService::class, 'importErrors');
    $errors_property->setValue($service, $errors);

    $modules_property = new ReflectionProperty(PhpDocGeneratorService::class, 'user_installed_modules');
    $modules_property->setValue($service, $user_modules);

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

test('a real error in an installed package is reported compactly by module name with a -v hint', function () {
    $out = reportImportErrors(
        ['some.module' => 'ImportError: cannot import name foo'],
        OutputInterface::VERBOSITY_NORMAL,
        user_modules: ['some'],
    );
    expect($out)->toContain('some.module');
    expect($out)->toContain('run with -v for details');
    expect($out)->not->toContain('cannot import name foo');
});

test('several real errors in installed packages are joined with commas', function () {
    $out = reportImportErrors([
        'a.mod'  => 'ImportError: cannot import name foo',
        'b.mod'  => 'ValueError: bad thing',
        'winreg' => "ModuleNotFoundError: No module named 'winreg'",
    ], OutputInterface::VERBOSITY_NORMAL, user_modules: ['a', 'b', 'winreg']);

    expect($out)->toContain('a.mod, b.mod');
    expect($out)->not->toContain('winreg');
});

test('failures outside installed packages are reported as a count with a -vv hint', function () {
    $out = reportImportErrors([
        'encodings.mbcs' => 'ImportError: cannot import name mbcs_encode',
        'stdlib.thing'   => 'ValueError: boom',
    ], OutputInterface::VERBOSITY_NORMAL);

    expect($out)->toContain('Could not import 2 modules that are not explicitly installed');
    expect($out)->toContain('run with -vv for details');
    expect($out)->not->toContain('encodings.mbcs');
});

test('-v shows installed-package errors in full but keeps only a count for the rest', function () {
    $out = reportImportErrors([
        'some.mod'       => 'ImportError: cannot import name foo',
        'encodings.mbcs' => 'ImportError: cannot import name mbcs_encode',
    ], OutputInterface::VERBOSITY_VERBOSE, user_modules: ['some']);

    expect($out)->toContain('some.mod: ImportError: cannot import name foo');
    expect($out)->toContain('Could not import 1 module that is not explicitly installed');
    expect($out)->not->toContain('encodings.mbcs');
});

test('-vv shows every error in full, including the ignorable ones', function () {
    $out = reportImportErrors([
        'some.mod'       => 'ImportError: cannot import name foo',
        'winreg'         => "ModuleNotFoundError: No module named 'winreg'",
        'encodings.mbcs' => 'ImportError: cannot import name mbcs_encode',
    ], OutputInterface::VERBOSITY_VERY_VERBOSE, user_modules: ['some']);

    expect($out)->toContain('some.mod: ImportError: cannot import name foo');
    expect($out)->toContain("winreg: ModuleNotFoundError: No module named 'winreg'");
    expect($out)->toContain('encodings.mbcs: ImportError: cannot import name mbcs_encode');
});

test('submodules are attributed to their installed root package', function () {
    $out = reportImportErrors(
        ['numpy.linalg.broken' => 'ImportError: cannot import name x'],
        OutputInterface::VERBOSITY_NORMAL,
        user_modules: ['numpy'],
    );
    expect($out)->toContain('numpy.linalg.broken');
    expect($out)->toContain('run with -v for details');
});

test('nothing is printed when there are no import errors', function () {
    expect(trim(reportImportErrors([], OutputInterface::VERBOSITY_NORMAL)))->toBe('');
});
