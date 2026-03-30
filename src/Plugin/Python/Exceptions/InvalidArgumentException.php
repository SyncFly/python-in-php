<?php

namespace Python_In_PHP\Plugin\Python\Exceptions;

class InvalidArgumentException extends \Exception
{
    public function __construct(string $string)
    {
        parent::__construct($string);
    }
}