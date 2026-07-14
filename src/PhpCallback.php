<?php

namespace Python_In_PHP;

/**
 * Marks a PHP callable to be exposed to Python as a callable. Mostly needed to
 * pass a function-name string (e.g. 'strlen'), which is not auto-detected.
 * Create one via {@see \Py::callback()}.
 */
final class PhpCallback
{
    /** @var callable */
    private $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    /** @return callable */
    public function getCallable(): callable
    {
        return $this->callable;
    }
}
