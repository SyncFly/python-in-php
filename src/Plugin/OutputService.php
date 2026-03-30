<?php

namespace Python_In_PHP\Plugin;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface as SymfonyOutputInterface;

class OutputService
{
    public function __construct(
        public SymfonyOutputInterface $controls
    ){
    }

    public function displayMessage(iterable|string $message, int $add_new_lines = 2, bool $add_spaces = true): void
    {
        $spaces = $add_spaces ? "  " : "";
        $second_new_line = $add_new_lines >= 2 ? str_repeat(PHP_EOL, $add_new_lines - 1) : "";
        $this->controls->write($spaces . $message . $second_new_line, $add_new_lines);
    }

    public function newLine(): void
    {
        $this->controls->write("", true);
    }

    public function displayHeader(iterable|string $message, bool $add_new_line = true, bool $add_spaces = true): void
    {
        $spaces = $add_spaces ? PHP_EOL . "  " : "";
        $second_new_line = $add_new_line ? PHP_EOL : "";
        $style = new OutputFormatterStyle('cyan', null, ['bold', 'underscore']);
        $this->controls->getFormatter()->setStyle('header', $style);
        $this->controls->write($spaces . "<header>$message</header>" . $second_new_line, $add_new_line);
    }

    public function verboseMessage(iterable|string $message): void
    {
        if ($this->controls->isVerbose()) {
            $this->controls->writeln($message);
        }
    }

    public function veryVerboseMessage(iterable|string $message): void
    {
        if ($this->controls->isVeryVerbose()) {
            $this->controls->writeln($message);
        }
    }

    public function debugMessage(iterable|string $message): void
    {
        if ($this->controls->isDebug()) {
            $this->controls->writeln($message);
        }
    }

    public function isDebug(): bool
    {
        return $this->controls->isDebug();
    }
}