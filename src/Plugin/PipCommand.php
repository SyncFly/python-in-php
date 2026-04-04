<?php

namespace Python_In_PHP\Plugin;

use Composer\Command\BaseCommand;
use Python_In_PHP\Plugin\Python\PythonManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PipCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('pip')
            ->setDescription('"Python-In-PHP" Python package manager')
            ->addArgument('action', InputArgument::IS_ARRAY, 'Action (install, require, remove, update, etc.) and arguments')
            ->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binDir = $this->getVendorBinDir();
        $vendorDir = $this->getVendorDir();
        $outputService = new OutputService($output);

        $action = $this->getAllArgumentsAndOptions();

        if (empty($action)) {
            $outputService->displayHeader('"Python-In-PHP" Python package manager');
            $outputService->displayMessage('Welcome!');
            return 0;
        }

        if ($action[0] != 'run') {
            $outputService->displayHeader('"Python-In-PHP" Python package manager');
        }
        $outputService->debugMessage($action);

        $python = new PythonManager($vendorDir, $binDir, $this->requireComposer(), $outputService);
        $python->runPipCommand($action);

        return 0;
    }

    public function getVendorBinDir()
    {
        return $this->requireComposer()->getConfig()->get('bin-dir') ?? ($this->getVendorDir() . DIRECTORY_SEPARATOR . 'bin');
    }

    public function getVendorDir()
    {
        return $this->requireComposer()->getConfig()->get('vendor-dir');
    }

    /**
     * @return string[]
     */
    private function getAllArgumentsAndOptions(): array
    {
        global $argv;
        $offset = array_find_key($argv, fn($arg) => $arg == $this->getName()) + 1;
        $result = array_slice($argv, $offset);
        return $result;
    }
}