<?php

namespace Python_In_PHP\Plugin\Python\Exceptions;

class UnsupportedOS extends \Exception
{
    function __construct(string $string)
    {
        parent::__construct($string);
    }
}