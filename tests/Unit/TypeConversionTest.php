<?php

use Python_In_PHP\PythonDict;

test('PythonDict supports ArrayAccess and Countable', function () {
    $dict = PythonDict::fromArray([0 => 'zero', 1 => 'one']);
    expect($dict[0])->toBe('zero');
    expect($dict[1])->toBe('one');
    expect(count($dict))->toBe(2);
});

test('PythonDict is iterable', function () {
    $dict = PythonDict::fromArray([0 => 'zero', 1 => 'one']);
    $collected = [];
    foreach ($dict as $k => $v) {
        $collected[$k] = $v;
    }
    expect($collected)->toBe([0 => 'zero', 1 => 'one']);
});

test('PythonDict serializes to __python_type__ wrapper', function () {
    $dict = PythonDict::fromArray([0 => 'zero', 1 => 'one']);
    $json = json_decode(json_encode($dict), true);
    expect($json['__python_type__'])->toBe('dict');
    expect($json['value']['0'])->toBe('zero');
    expect($json['value']['1'])->toBe('one');
});

test('empty PythonDict serializes with __python_type__ wrapper', function () {
    $dict = PythonDict::fromArray([]);
    $json = json_decode(json_encode($dict), true);
    expect($json['__python_type__'])->toBe('dict');
    expect($json['value'])->toBeEmpty();
});
