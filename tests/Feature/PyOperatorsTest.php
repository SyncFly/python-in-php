<?php

use py\numpy;

// Py operator helpers apply the real Python operator, which PHP either lacks or handles
// differently (list "+" concatenates, "*" repeats, "//"/"% " floor, "@" is matmul, …).

beforeEach(function () {
    if (!canOpenLocalTcpSocket()) {
        $this->markTestSkipped('Local TCP sockets are not available in this environment');
    }
});

test('plus adds numbers, concatenates strings, and concatenates lists', function () {
    expect(Py::plus(2, 3))->toBe(5);
    expect(Py::plus('a', 'b'))->toBe('ab');            // PHP "+" can't do this
    expect(Py::plus([1, 2], [3, 4]))->toBe([1, 2, 3, 4]); // PHP "+" would union, not concat
});

test('times multiplies numbers and repeats sequences', function () {
    expect(Py::times(4, 3))->toBe(12);
    expect(Py::times([1, 2], 3))->toBe([1, 2, 1, 2, 1, 2]); // list repetition
});

test('minus, divide and power', function () {
    expect(Py::minus(5, 3))->toBe(2);
    expect(Py::divide(7, 2))->toBe(3.5);
    expect(Py::power(2, 10))->toBe(1024);
});

test('floorDivide and modulo use Python floor semantics for negatives', function () {
    expect(Py::floorDivide(-7, 2))->toBe(-4); // PHP intdiv(-7, 2) == -3
    expect(Py::modulo(-7, 3))->toBe(2);       // PHP -7 % 3 == -1
});

test('negative negates a value', function () {
    expect(Py::negative(5))->toBe(-5);
});

test('comparison operators return a bool', function () {
    expect(Py::lt(2, 3))->toBeTrue();
    expect(Py::ge(3, 3))->toBeTrue();
    expect(Py::eq(2, 2))->toBeTrue();
    expect(Py::ne(2, 3))->toBeTrue();
});

test('contains implements Python\'s "in"', function () {
    expect(Py::contains([1, 2, 3], 2))->toBeTrue();
    expect(Py::contains([1, 2, 3], 5))->toBeFalse();
});

test('bitwise operators work on integers', function () {
    expect(Py::bitOr(5, 2))->toBe(7);
    expect(Py::bitAnd(6, 3))->toBe(2);
    expect(Py::bitXor(5, 3))->toBe(6);
    expect(Py::leftShift(1, 4))->toBe(16);
});

test('matmul multiplies matrices (an operator PHP has no equivalent for)', function () {
    $a = numpy::array([[1, 2], [3, 4]]);
    $b = numpy::array([[5, 6], [7, 8]]);
    expect(Py::matmul($a, $b)->tolist())->toBe([[19, 22], [43, 50]]);
});

test('operator() applies any operator by its Python name', function () {
    expect(Py::operator('add', 10, 5))->toBe(15);
    expect(Py::operator('mul', 6, 7))->toBe(42);
});

test('expr evaluates a single binary infix expression', function () {
    expect(Py::expr(2, '+', 3))->toBe(5);
    expect(Py::expr([1, 2], '+', [3, 4]))->toBe([1, 2, 3, 4]); // Python list concatenation
});

test('expr honours Python operator precedence', function () {
    // 1 + 6 * 2 == 13, not (1 + 6) * 2 == 14
    expect(Py::expr(1, '+', 6, '*', 2))->toBe(13);
    // 6 * 2 / 4 == 3.0 (left-to-right, same precedence)
    expect(Py::expr(6, '*', 2, '/', 4))->toBe(3.0);
    // ** is right-associative: 2 ** 3 ** 2 == 2 ** 9 == 512
    expect(Py::expr(2, '**', 3, '**', 2))->toBe(512);
    // ** binds tighter than unary/mul: 3 * 2 ** 3 == 24
    expect(Py::expr(3, '*', 2, '**', 3))->toBe(24);
});

test('expr chains numpy operands element-wise', function () {
    $a = numpy::array([1, 2, 3]);
    $b = numpy::array([4, 5, 6]);
    // a + b * 2 == [9, 12, 15]
    expect(Py::expr($a, '+', $b, '*', 2)->tolist())->toBe([9, 12, 15]);
});

test('expression is an alias of expr', function () {
    expect(Py::expression(10, '-', 3))->toBe(7);
});

test('expr rejects a malformed token sequence', function () {
    expect(fn () => Py::expr(1, '+'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Py::expr(1, 2, 3))->toThrow(InvalidArgumentException::class);
});
