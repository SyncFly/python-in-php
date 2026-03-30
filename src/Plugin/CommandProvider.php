<?php

namespace Python_In_PHP\Plugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandProvider implements CommandProviderCapability
{
    public function __construct()
    {
        require_once __DIR__ . "/polyfills.php";
    }

    public function getCommands()
    {
        return [
            new PipCommand(),
        ];
    }
}