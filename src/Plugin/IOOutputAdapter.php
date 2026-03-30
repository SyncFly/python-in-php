<?php

namespace Python_In_PHP\Plugin;

use Composer\IO\IOInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapts Composer's IOInterface to Symfony's OutputInterface
 * so OutputService can be used from plugin event handlers.
 */
class IOOutputAdapter implements OutputInterface
{
    private OutputFormatter $formatter;

    public function __construct(private IOInterface $io)
    {
        $this->formatter = new OutputFormatter();
    }

    public function write(iterable|string $messages, bool $newline = false, int $options = 0): void
    {
        foreach ((array)$messages as $message) {
            $this->io->write($message, $newline);
        }
    }

    public function writeln(iterable|string $messages, int $options = 0): void
    {
        foreach ((array)$messages as $message) {
            $this->io->write($message);
        }
    }

    public function setVerbosity(int $level): void
    {
    }

    public function getVerbosity(): int
    {
        if ($this->io->isDebug()) return OutputInterface::VERBOSITY_DEBUG;
        if ($this->io->isVeryVerbose()) return OutputInterface::VERBOSITY_VERY_VERBOSE;
        if ($this->io->isVerbose()) return OutputInterface::VERBOSITY_VERBOSE;
        return OutputInterface::VERBOSITY_NORMAL;
    }

    public function isQuiet(): bool
    {
        return false;
    }

    public function isVerbose(): bool
    {
        return $this->io->isVerbose();
    }

    public function isVeryVerbose(): bool
    {
        return $this->io->isVeryVerbose();
    }

    public function isDebug(): bool
    {
        return $this->io->isDebug();
    }

    public function setDecorated(bool $decorated): void
    {
    }

    public function isDecorated(): bool
    {
        return false;
    }

    public function setFormatter(OutputFormatter|OutputFormatterInterface $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function getFormatter(): OutputFormatterInterface
    {
        return $this->formatter;
    }

    public function isSilent(): bool
    {
        return false;
    }
}
