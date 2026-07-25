<?php

namespace Python_In_PHP\Plugin;

use Composer\Command\BaseCommand;
use Python_In_PHP\Plugin\Python\PythonManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PythonCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('python')
            ->setDescription('Run the project\'s Python: `python <args>`, a script `python script.py`, or set the version `python use 3.12`')
            ->addArgument('action', InputArgument::IS_ARRAY, 'Python arguments / a .py script, or `use [version]` to get or set the Python version')
            ->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binDir = $this->getVendorBinDir();
        $vendorDir = $this->getVendorDir();
        $outputService = new OutputService($output);

        $action = $this->getAllArgumentsAndOptions();

        $python = new PythonManager($vendorDir, $binDir, $this->requireComposer(), $outputService);

        // `python use [version]` gets or sets the project's Python version
        if (($action[0] ?? null) === 'use') {
            $version = $action[1] ?? null;
            if ($version === null) {
                $outputService->displayMessage("Current Python version: " . $python->getPythonVersion());
                return 0;
            }
            $python->setPythonVersion($version);
            return 0;
        }

        // Otherwise forward everything to the Python binary (a script path, -c "...", --version, …)
        return $python->run($action);
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
        return array_slice($argv, $offset);
    }
}
