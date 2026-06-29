<?php

namespace Python_In_PHP;

class PythonException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $traceback = '',
    ) {
        parent::__construct($message);
    }
}
