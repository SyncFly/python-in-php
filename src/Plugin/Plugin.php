<?php

namespace Python_In_PHP\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Python_In_PHP\Plugin\Python\PythonManager1;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    function activate(Composer $composer, IOInterface $io)
    {
        require_once __DIR__ . '/polyfills.php';
        $this->composer = $composer;
        $this->io = $io;
    }

    function deactivate(Composer $composer, IOInterface $io)
    {
    }

    function uninstall(Composer $composer, IOInterface $io)
    {
    }

    function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Python_In_PHP\Plugin\CommandProvider',
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD  => 'onInstallOrUpdate',
        ];
    }

    public function onInstallOrUpdate(Event $event): void
    {
        $outputService = new OutputService(new IOOutputAdapter($this->io));
        $binDir = $this->composer->getConfig()->get('bin-dir');
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        $outputService->newLine();
        $outputService->displayMessage("Python-In-PHP", 1);
        $python = new PythonManager1($vendorDir, $binDir, $this->composer, $outputService);
        $python->handleInstall();
    }
}