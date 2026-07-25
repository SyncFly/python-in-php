<?php

use Python_In_PHP\PythonBridge;
use Python_In_PHP\PhpCallback;

class Py
{
    /** Bridge the `operator` module has already been imported into (operators are lazy). */
    private static ?PythonBridge $operator_imported = null;

    static function instance(): ?PythonBridge
    {
        return PythonBridge::getInstance();
    }

    /** The running bridge, started on first use if it isn't already. */
    static function bridge(): PythonBridge
    {
        return PythonBridge::startOrGetRunning();
    }

    /**
     * Wrap a PHP callable so Python can invoke it. Bare callables are auto-detected;
     * use this for function-name strings or to make intent explicit.
     */
    static function callback(callable $callable): PhpCallback
    {
        return new PhpCallback($callable);
    }

    static function startIfNotStarted(array $options = []): void
    {
        PythonBridge::startOrGetRunning($options);
    }

    static function isRunning(): bool
    {
        return self::instance()?->isRunning() ?? false;
    }

    function stop(): void
    {
        self::instance()?->__destruct();
    }

    // ===== Python core =====

    /** Run Python statements (no return value), like Python's exec(). */
    static function exec(string $code)
    {
        return self::bridge()->exec($code);
    }

    /** Evaluate a Python expression and return its result, like Python's eval(). */
    static function eval(string $code)
    {
        return self::bridge()->eval($code);
    }

    /** Import a module (optionally aliased) and return it as a Python object. */
    static function import(string $module, ?string $alias = null)
    {
        return self::bridge()->importModule($module, $alias);
    }

    /**
     * Call any Python builtin by name. Positional arguments map to Python positional
     * args; PHP named arguments map to Python keyword args, e.g.
     * Py::builtin('sorted', $items, reverse: true).
     */
    public static function builtin(string $name, mixed ...$args)
    {
        $positional = [];
        $kwargs = [];
        foreach ($args as $key => $value) {
            if (is_int($key)) $positional[] = $value;
            else $kwargs[$key] = $value;
        }
        return self::bridge()->call($name, $positional, $kwargs);
    }

    // ===== Python builtins (things PHP lacks or handles differently) =====
    // Each forwards to its Python builtin; PHP named args become Python kwargs.

    static function len(mixed ...$args) { return self::builtin('len', ...$args); }
    static function sum(mixed ...$args) { return self::builtin('sum', ...$args); }
    static function abs(mixed ...$args) { return self::builtin('abs', ...$args); }
    static function round(mixed ...$args) { return self::builtin('round', ...$args); }
    static function pow(mixed ...$args) { return self::builtin('pow', ...$args); }
    static function divmod(mixed ...$args) { return self::builtin('divmod', ...$args); }
    static function min(mixed ...$args) { return self::builtin('min', ...$args); }
    static function max(mixed ...$args) { return self::builtin('max', ...$args); }

    static function sorted(mixed ...$args) { return self::builtin('sorted', ...$args); }
    static function reversed(mixed ...$args) { return self::builtin('reversed', ...$args); }
    static function enumerate(mixed ...$args) { return self::builtin('enumerate', ...$args); }
    static function zip(mixed ...$args) { return self::builtin('zip', ...$args); }
    static function map(mixed ...$args) { return self::builtin('map', ...$args); }
    static function filter(mixed ...$args) { return self::builtin('filter', ...$args); }
    static function range(mixed ...$args) { return self::builtin('range', ...$args); }
    static function iter(mixed ...$args) { return self::builtin('iter', ...$args); }
    static function next(mixed ...$args) { return self::builtin('next', ...$args); }
    static function any(mixed ...$args) { return self::builtin('any', ...$args); }
    static function all(mixed ...$args) { return self::builtin('all', ...$args); }

    static function list(mixed ...$args) { return self::builtin('list', ...$args); }
    static function dict(mixed ...$args) { return self::builtin('dict', ...$args); }
    static function set(mixed ...$args) { return self::builtin('set', ...$args); }
    static function tuple(mixed ...$args) { return self::builtin('tuple', ...$args); }
    static function frozenset(mixed ...$args) { return self::builtin('frozenset', ...$args); }

    static function str(mixed ...$args) { return self::builtin('str', ...$args); }
    static function int(mixed ...$args) { return self::builtin('int', ...$args); }
    static function float(mixed ...$args) { return self::builtin('float', ...$args); }
    static function bool(mixed ...$args) { return self::builtin('bool', ...$args); }
    static function bytes(mixed ...$args) { return self::builtin('bytes', ...$args); }

    static function repr(mixed ...$args) { return self::builtin('repr', ...$args); }
    static function type(mixed ...$args) { return self::builtin('type', ...$args); }
    static function isinstance(mixed ...$args) { return self::builtin('isinstance', ...$args); }
    static function hasattr(mixed ...$args) { return self::builtin('hasattr', ...$args); }
    static function getattr(mixed ...$args) { return self::builtin('getattr', ...$args); }
    static function setattr(mixed ...$args) { return self::builtin('setattr', ...$args); }
    static function callable(mixed ...$args) { return self::builtin('callable', ...$args); }
    static function hash(mixed ...$args) { return self::builtin('hash', ...$args); }
    static function id(mixed ...$args) { return self::builtin('id', ...$args); }
    static function dir(mixed ...$args) { return self::builtin('dir', ...$args); }

    static function chr(mixed ...$args) { return self::builtin('chr', ...$args); }
    static function ord(mixed ...$args) { return self::builtin('ord', ...$args); }
    static function hex(mixed ...$args) { return self::builtin('hex', ...$args); }
    static function oct(mixed ...$args) { return self::builtin('oct', ...$args); }
    static function bin(mixed ...$args) { return self::builtin('bin', ...$args); }
    static function format(mixed ...$args) { return self::builtin('format', ...$args); }

    static function print(mixed ...$args) { return self::builtin('print', ...$args); }
    static function open(mixed ...$args) { return self::builtin('open', ...$args); }

    // ===== Python operators =====
    // PHP operators don't work on Python objects (numpy arrays, lists, sets, custom __add__…)
    // and differ in behaviour (list "+" concatenates, "|" unions sets/dicts, "@" is matmul,
    // "//" and "%" floor toward negative infinity). These apply the real Python operator.

    /**
     * Apply a Python operator by its `operator` module name, e.g. Py::operator('add', $a, $b).
     * The `operator` module is imported lazily on first use.
     */
    public static function operator(string $name, mixed ...$args)
    {
        $bridge = self::bridge();
        if (self::$operator_imported !== $bridge) {
            $bridge->importModule('operator');
            self::$operator_imported = $bridge;
        }
        return self::builtin('operator.' . $name, ...$args);
    }

    // Arithmetic
    static function plus(mixed $a, mixed $b) { return self::operator('add', $a, $b); }
    static function minus(mixed $a, mixed $b) { return self::operator('sub', $a, $b); }
    static function times(mixed $a, mixed $b) { return self::operator('mul', $a, $b); }
    static function divide(mixed $a, mixed $b) { return self::operator('truediv', $a, $b); }
    static function floorDivide(mixed $a, mixed $b) { return self::operator('floordiv', $a, $b); }
    static function modulo(mixed $a, mixed $b) { return self::operator('mod', $a, $b); }
    static function power(mixed $a, mixed $b) { return self::operator('pow', $a, $b); }
    static function matmul(mixed $a, mixed $b) { return self::operator('matmul', $a, $b); }
    static function negative(mixed $a) { return self::operator('neg', $a); }
    static function positive(mixed $a) { return self::operator('pos', $a); }

    // Bitwise ("|" and "&" also union/intersect Python sets and dicts)
    static function bitAnd(mixed $a, mixed $b) { return self::operator('and_', $a, $b); }
    static function bitOr(mixed $a, mixed $b) { return self::operator('or_', $a, $b); }
    static function bitXor(mixed $a, mixed $b) { return self::operator('xor', $a, $b); }
    static function bitNot(mixed $a) { return self::operator('invert', $a); }
    static function leftShift(mixed $a, mixed $b) { return self::operator('lshift', $a, $b); }
    static function rightShift(mixed $a, mixed $b) { return self::operator('rshift', $a, $b); }

    // Comparison (numpy and other objects may return an element-wise result, not a bool)
    static function eq(mixed $a, mixed $b) { return self::operator('eq', $a, $b); }
    static function ne(mixed $a, mixed $b) { return self::operator('ne', $a, $b); }
    static function lt(mixed $a, mixed $b) { return self::operator('lt', $a, $b); }
    static function le(mixed $a, mixed $b) { return self::operator('le', $a, $b); }
    static function gt(mixed $a, mixed $b) { return self::operator('gt', $a, $b); }
    static function ge(mixed $a, mixed $b) { return self::operator('ge', $a, $b); }

    /** Python's `in`: whether $container holds $item. */
    static function contains(mixed $container, mixed $item) { return self::operator('contains', $container, $item); }

    /** Infix operator symbol => [operator-module function, precedence (higher binds tighter), right-associative]. */
    private const EXPR_OPERATORS = [
        '**' => ['pow', 8, true],
        '*'  => ['mul', 7, false],
        '@'  => ['matmul', 7, false],
        '/'  => ['truediv', 7, false],
        '//' => ['floordiv', 7, false],
        '%'  => ['mod', 7, false],
        '+'  => ['add', 6, false],
        '-'  => ['sub', 6, false],
        '<<' => ['lshift', 5, false],
        '>>' => ['rshift', 5, false],
        '&'  => ['and_', 4, false],
        '^'  => ['xor', 3, false],
        '|'  => ['or_', 2, false],
        '==' => ['eq', 1, false],
        '!=' => ['ne', 1, false],
        '<'  => ['lt', 1, false],
        '<=' => ['le', 1, false],
        '>'  => ['gt', 1, false],
        '>=' => ['ge', 1, false],
    ];

    /**
     * Evaluate an infix expression given as alternating operands and operator symbols, e.g.
     * Py::expr($a, '+', $b, '*', $c). Operators use Python semantics and precedence
     * (so '*' binds tighter than '+', '**' is right-associative). Operands may be Python
     * objects, scalars or arrays; intermediate results stay on the Python side.
     */
    public static function expr(mixed ...$tokens)
    {
        $tokens = array_values($tokens);
        $count = count($tokens);
        if ($count === 0) {
            throw new \InvalidArgumentException('Py::expr() requires at least one operand');
        }
        if ($count % 2 === 0) {
            throw new \InvalidArgumentException('Py::expr() expects operands and operators to alternate, e.g. Py::expr($a, "+", $b)');
        }

        $values = [$tokens[0]];
        $ops = [];

        $apply = function () use (&$values, &$ops) {
            $symbol = array_pop($ops);
            $b = array_pop($values);
            $a = array_pop($values);
            $values[] = self::operator(self::EXPR_OPERATORS[$symbol][0], $a, $b);
        };

        for ($i = 1; $i < $count; $i += 2) {
            $symbol = $tokens[$i];
            if (!is_string($symbol) || !isset(self::EXPR_OPERATORS[$symbol])) {
                throw new \InvalidArgumentException("Py::expr(): expected an operator symbol at position $i, got " . var_export($symbol, true));
            }
            [, $precedence, $right_associative] = self::EXPR_OPERATORS[$symbol];

            while ($ops) {
                [, $top_precedence] = self::EXPR_OPERATORS[end($ops)];
                if ($top_precedence > $precedence || ($top_precedence === $precedence && !$right_associative)) {
                    $apply();
                } else {
                    break;
                }
            }

            $ops[] = $symbol;
            $values[] = $tokens[$i + 1];
        }

        while ($ops) {
            $apply();
        }

        return $values[0];
    }

    /** Alias of Py::expr(). */
    public static function expression(mixed ...$tokens)
    {
        return self::expr(...$tokens);
    }

    public static function __callStatic($name, $arguments)
    {
        return self::bridge()->$name(...$arguments);
    }

    function runInCloud()
    {
        //@TODO
    }

    function sharedFilesWithCloud()
    {
        //@TODO
    }
}
