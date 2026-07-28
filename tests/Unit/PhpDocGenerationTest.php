<?php

use Python_In_PHP\Plugin\OutputService;
use Python_In_PHP\Plugin\Python\Services\PhpDocGeneratorService;
use Symfony\Component\Console\Output\BufferedOutput;

// Stub generation must survive Python names that can't become PHP identifiers
// (e.g. classes registered under dotted aliases like 'pkg.module_name').

function generateDocs(array $structures): array
{
    $service = (new ReflectionClass(PhpDocGeneratorService::class))->newInstanceWithoutConstructor();

    $output_property = new ReflectionProperty(PhpDocGeneratorService::class, 'output');
    $output_property->setValue($service, new OutputService(new BufferedOutput()));

    $method = new ReflectionMethod($service, 'generateForModules');
    return iterator_to_array($method->invoke($service, $structures), preserve_keys: true);
}

test('a class registered under a dotted alias is skipped instead of crashing generation', function () {
    $docs = generateDocs([
        'voxtral_realtime' => [
            'classes' => [
                'voxtral_realtime.processing_voxtral_realtime' => [],
                'Processor' => [],
            ],
        ],
    ]);

    $paths = array_keys($docs);
    expect($paths)->toContain('py' . DIRECTORY_SEPARATOR . 'voxtral_realtime.php');
    expect($paths)->toContain('py\voxtral_realtime' . DIRECTORY_SEPARATOR . 'Processor.php');
    expect(implode(',', $paths))->not->toContain('processing_voxtral_realtime');
});

test('a PHP-keyword class name still gets the underscore prefix', function () {
    $docs = generateDocs(['builtins' => ['classes' => ['list' => []]]]);
    expect(array_keys($docs))->toContain('py\builtins' . DIRECTORY_SEPARATOR . '_list.php');
});

test('a module attribute with a non-identifier name is skipped, valid ones survive', function () {
    $docs = generateDocs([
        'mod' => [
            'attributes' => [
                ['name' => 'weird.name', 'type' => 'str'],
                ['name' => 'fine', 'type' => 'str'],
            ],
        ],
    ]);

    $content = $docs['py' . DIRECTORY_SEPARATOR . 'mod.php'];
    expect($content)->toContain('$fine');
    expect($content)->not->toContain('weird');
});
