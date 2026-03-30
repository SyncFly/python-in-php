<?php

use py\json;
use py\sys;
use py\datetime\datetime;

test('static method call', function () {
    expect(json::dumps(['a' => 'b']))->toBe('{"a": "b"}');
});

test('nested method call with nested class namespace', function () {
    expect(datetime::now()->isoformat())->toBeString();
});

test('attribute call', function () {
    expect(datetime::now()->day)->toBeInt();
});

test('static attribute call', function () {
    expect(sys::$platform)->toBeString();
});

test('class construction', function () {
    expect((new datetime(1914, 1, 1))->year)->toBe(1914);
});